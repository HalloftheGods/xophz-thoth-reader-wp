<?php
/**
 * REST API Endpoints for Thoth Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Thoth_Reader_API {

    /** Gemini calls one user may make per window, across both AI routes. */
    const AI_MAX_CALLS_PER_WINDOW = 20;
    const AI_WINDOW_SECONDS       = HOUR_IN_SECONDS;
    /** A Thoth deck is 78 cards; anything far above that is not a real reading. */
    const AI_MAX_CARDS            = 100;
    /** Saved readings one user may write per window. Each save is a 30-day transient. */
    const SAVE_MAX_PER_WINDOW     = 60;
    /** Largest saved reading, as JSON. A full reading with its interpretation is far smaller. */
    const SAVE_MAX_BYTES          = 65536;
    /** Reading ids match the GET route's pattern. */
    const SAVE_ID_PATTERN         = '/^[A-Za-z0-9_-]{1,64}$/';

    public function register_routes() {
        register_rest_route( 'thoth-reader/v1', '/health', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'health_check' ),
            'permission_callback' => '__return_true',
        ) );

        // Saving writes to the database, so it needs a logged-in user, a size cap and a per-user rate limit.
        register_rest_route( 'thoth-reader/v1', '/readings/save', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_reading' ),
            'permission_callback' => array( $this, 'can_save_reading' ),
        ) );

        register_rest_route( 'thoth-reader/v1', '/readings/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_reading' ),
            'permission_callback' => '__return_true',
        ) );

        // Both routes spend the owner's Gemini key, so they need a logged-in user and a per-user rate limit.
        register_rest_route( 'thoth-reader/v1', '/reading/interpret', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'interpret_reading' ),
            'permission_callback' => array( $this, 'can_use_ai' ),
        ) );

        register_rest_route( 'thoth-reader/v1', '/reading/synthesize-narrative', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'synthesize_narrative' ),
            'permission_callback' => array( $this, 'can_use_ai' ),
        ) );
    }

    /**
     * Permission for the routes that call Gemini: any logged-in user. The page shell hands a
     * logged-in visitor an X-WP-Nonce (window.wpApiSettings.nonce), which core's cookie
     * authentication checks before this runs.
     */
    public function can_use_ai() {
        if ( is_user_logged_in() ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', 'Log in to request a reading.', array( 'status' => 401 ) );
    }

    /**
     * Permission for saving a reading: any logged-in user, same as the AI routes.
     */
    public function can_save_reading() {
        if ( is_user_logged_in() ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', 'Log in to save a reading.', array( 'status' => 401 ) );
    }

    /**
     * Counts one Gemini call against the current user's hourly allowance.
     *
     * @return true|WP_Error WP_Error with status 429 once the allowance is spent.
     */
    private function consume_ai_allowance() {
        return $this->consume_allowance( 'thoth_ai_rate_', self::AI_MAX_CALLS_PER_WINDOW, 'Reading limit reached. Please try again later.' );
    }

    /**
     * Counts one call against the current user's hourly allowance for one bucket.
     *
     * @param string $bucket  Transient prefix; the user id is appended.
     * @param int    $max     Calls allowed per window.
     * @param string $message Message for the 429 error.
     * @return true|WP_Error WP_Error with status 429 once the allowance is spent.
     */
    private function consume_allowance( $bucket, $max, $message ) {
        $key   = $bucket . get_current_user_id();
        $now   = time();
        $state = get_transient( $key );

        $is_window_open = is_array( $state ) && isset( $state['count'], $state['reset'] ) && $state['reset'] > $now;
        if ( ! $is_window_open ) {
            $state = array(
                'count' => 0,
                'reset' => $now + self::AI_WINDOW_SECONDS,
            );
        }

        if ( $state['count'] >= $max ) {
            return new WP_Error(
                'rate_limited',
                $message,
                array( 'status' => 429, 'retry_after' => $state['reset'] - $now )
            );
        }

        $state['count']++;
        set_transient( $key, $state, max( 1, $state['reset'] - $now ) );

        return true;
    }

    public function health_check() {
        return rest_ensure_response( array(
            'status'  => 'ok',
            'service' => 'Book of Thoth Tarot Companion (WordPress Plugin)',
        ) );
    }

    public function save_reading( WP_REST_Request $request ) {
        $id           = (string) $request->get_param( 'id' );
        $reading_data = $request->get_param( 'readingData' );

        if ( '' === $id || empty( $reading_data ) ) {
            return new WP_Error( 'missing_params', 'Missing reading ID or data', array( 'status' => 400 ) );
        }

        if ( ! preg_match( self::SAVE_ID_PATTERN, $id ) ) {
            return new WP_Error( 'invalid_id', 'Reading ID may use only letters, digits, - and _ (64 at most).', array( 'status' => 400 ) );
        }

        if ( ! is_array( $reading_data ) ) {
            return new WP_Error( 'invalid_reading', 'Reading data must be a JSON object.', array( 'status' => 400 ) );
        }

        $encoded = wp_json_encode( $reading_data );
        if ( false === $encoded || strlen( $encoded ) > self::SAVE_MAX_BYTES ) {
            return new WP_Error( 'reading_too_large', 'Reading data is too large to save.', array( 'status' => 413 ) );
        }

        $user_id       = get_current_user_id();
        $transient_key = 'thoth_reading_' . $id;
        $existing      = get_transient( $transient_key );
        if ( is_array( $existing ) && isset( $existing['ownerId'] ) && (int) $existing['ownerId'] !== $user_id ) {
            return new WP_Error( 'not_owner', 'Another account already saved a reading with this ID.', array( 'status' => 403 ) );
        }

        $allowance = $this->consume_allowance( 'thoth_save_rate_', self::SAVE_MAX_PER_WINDOW, 'Save limit reached. Please try again later.' );
        if ( is_wp_error( $allowance ) ) {
            return $allowance;
        }

        $payload = array(
            'readingData' => json_decode( $encoded, true ),
            'createdAt'   => current_time( 'mysql' ),
            'ownerId'     => $user_id,
        );

        set_transient( $transient_key, $payload, MONTH_IN_SECONDS );

        return rest_ensure_response( array(
            'success' => true,
            'id'      => $id,
        ) );
    }

    public function get_reading( WP_REST_Request $request ) {
        $id            = sanitize_text_field( $request->get_param( 'id' ) );
        $transient_key = 'thoth_reading_' . $id;
        $reading       = get_transient( $transient_key );

        if ( false === $reading ) {
            return new WP_Error( 'not_found', 'Reading not found or expired', array( 'status' => 404 ) );
        }

        // A shared reading link does not reveal which account saved it.
        if ( is_array( $reading ) ) {
            unset( $reading['ownerId'] );
        }

        return rest_ensure_response( array(
            'success' => true,
            'reading' => $reading,
        ) );
    }

    private function get_gemini_key() {
        return Thoth_Reader_Connectors::get_key( 'google' );
    }

    private function get_gemini_model() {
        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google']['options']['model']['setting_name'] ) ) {
                $model = get_option( $connectors['google']['options']['model']['setting_name'], '' );
                if ( ! empty( $model ) ) {
                    return $model;
                }
            }
        }
        return 'gemini-3.6-flash';
    }

    private function call_gemini( $prompt ) {
        $api_key = $this->get_gemini_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'The Google Gemini key is not configured. Add it in Settings > Connectors.', array( 'status' => 500 ) );
        }

        $model = $this->get_gemini_model();
        $url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
        $body = array(
            'contents'         => array(
                array(
                    'parts' => array(
                        array( 'text' => $prompt ),
                    ),
                ),
            ),
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
            ),
        );

        $response = wp_remote_post( $url, array(
            'headers'     => array(
                'Content-Type'   => 'application/json',
                'User-Agent'     => 'aistudio-build',
                'x-goog-api-key' => $api_key,
            ),
            'body'        => wp_json_encode( $body ),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gemini_error', $response->get_error_message(), array( 'status' => 500 ) );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( 200 !== $response_code ) {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Failed to call Gemini API.';
            return new WP_Error( 'gemini_error', $error_message, array( 'status' => $response_code ) );
        }

        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $result_text = $data['candidates'][0]['content']['parts'][0]['text'];
            $parsed      = json_decode( $result_text, true );
            return $parsed ? $parsed : array( 'raw' => $result_text );
        }

        return new WP_Error( 'gemini_error', 'Invalid response format from Gemini.', array( 'status' => 500 ) );
    }

    public function interpret_reading( WP_REST_Request $request ) {
        $question      = $request->get_param( 'question' );
        $pulled_cards  = $request->get_param( 'pulledCards' );
        $counting_log  = $request->get_param( 'countingLog' );
        $deck_note     = $request->get_param( 'deckNote' );

        if ( empty( $pulled_cards ) || ! is_array( $pulled_cards ) ) {
            return new WP_Error( 'missing_cards', 'No cards provided for interpretation.', array( 'status' => 400 ) );
        }

        if ( count( $pulled_cards ) > self::AI_MAX_CARDS ) {
            return new WP_Error( 'too_many_cards', 'Too many cards for one reading.', array( 'status' => 400 ) );
        }

        $card_summary_list = implode( "\n\n", array_map( function( $card, $idx ) {
            return sprintf(
                "Step %d: [%s] (%s)\n  - Category: %s | Numerology Count: %s\n  - Hebrew Letter: %s\n  - Astrological Attribution: %s\n  - Alchemical Element/Process: %s\n  - Tree of Life: %s\n  - Occult Key Themes: %s",
                $idx + 1,
                isset( $card['name'] ) ? $card['name'] : '',
                isset( $card['thothTitle'] ) ? $card['thothTitle'] : 'Atu',
                isset( $card['category'] ) ? $card['category'] : '',
                isset( $card['numerologyCount'] ) ? $card['numerologyCount'] : '',
                isset( $card['hebrewLetter']['name'] ) ? $card['hebrewLetter']['name'] : 'N/A',
                isset( $card['astrology']['name'] ) ? $card['astrology']['name'] : 'N/A',
                isset( $card['alchemy']['element'] ) ? $card['alchemy']['element'] : 'N/A',
                isset( $card['treeOfLife']['sephiraOrPath'] ) ? $card['treeOfLife']['sephiraOrPath'] : 'N/A',
                isset( $card['keyThemes'] ) && is_array( $card['keyThemes'] ) ? implode( ', ', $card['keyThemes'] ) : ''
            );
        }, $pulled_cards, array_keys( $pulled_cards ) ) );

        $prompt = sprintf(
            "You are the wise, timeless Narrator of the Book of Thoth and Hermetic Grimoires.\nYou are telling an allegorical, deeply mystical story to the querent using the sequence of cards pulled in their custom Thoth Numerological Counting Spread loop.\n\nTHE HOOK OF THE BOOK (PULLED CARDS SEQUENCE):\n%s\n\nQUERENT'S QUESTION & INITIATIVE (THE USER'S VOICE):\n\"%s\"\n\nPlease provide a deeply insightful, eloquent, and authentic Thoth Tarot Reading structured into JSON with key fields: title, overview, bookHook, numerologicalJourney, cardByCardAnalysis, deepAnswer, actionableCounsel, alchemicalFormula, bookChapters, poeticNarrative.\nRespond strictly in valid JSON format.",
            $card_summary_list,
            $question ? $question : 'General Spiritual & Alchemical Guidance for the Querent'
        );

        $allowance = $this->consume_ai_allowance();
        if ( is_wp_error( $allowance ) ) {
            return $allowance;
        }

        $result = $this->call_gemini( $prompt );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success'        => true,
            'interpretation' => $result,
        ) );
    }

    public function synthesize_narrative( WP_REST_Request $request ) {
        $question     = $request->get_param( 'question' );
        $pulled_cards = $request->get_param( 'pulledCards' );
        $style        = $request->get_param( 'style' );

        if ( empty( $pulled_cards ) || ! is_array( $pulled_cards ) ) {
            return new WP_Error( 'missing_cards', 'No cards provided for narrative synthesis.', array( 'status' => 400 ) );
        }

        if ( count( $pulled_cards ) > self::AI_MAX_CARDS ) {
            return new WP_Error( 'too_many_cards', 'Too many cards for one reading.', array( 'status' => 400 ) );
        }

        $card_names = implode( ' -> ', array_map( function( $c ) { return isset( $c['name'] ) ? $c['name'] : ''; }, $pulled_cards ) );

        $prompt = sprintf(
            "Synthesize a rich, multi-chapter allegorical book narrative for this Tarot reading.\nQUERENT'S QUESTION: \"%s\"\nCARD SEQUENCE: %s\nNARRATIVE STYLE: %s\n\nOutput a JSON object with: title, stanzas, proseSynthesis, keyMotto, bookHook, bookChapters, style.\nRespond strictly in valid JSON format.",
            $question ? $question : 'Spiritual Awakening & Divine Alignment',
            $card_names,
            $style ? $style : 'crowleyan'
        );

        $allowance = $this->consume_ai_allowance();
        if ( is_wp_error( $allowance ) ) {
            return $allowance;
        }

        $result = $this->call_gemini( $prompt );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success'   => true,
            'narrative' => $result,
        ) );
    }
}
