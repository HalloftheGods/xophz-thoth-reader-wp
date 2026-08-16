<?php
/**
 * REST API Endpoints for Thoth Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Thoth_Reader_API {

    public function register_routes() {
        register_rest_route( 'thoth-reader/v1', '/health', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'health_check' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'thoth-reader/v1', '/readings/save', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_reading' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'thoth-reader/v1', '/readings/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_reading' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'thoth-reader/v1', '/reading/interpret', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'interpret_reading' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'thoth-reader/v1', '/reading/synthesize-narrative', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'synthesize_narrative' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function health_check() {
        return rest_ensure_response( array(
            'status'  => 'ok',
            'service' => 'Book of Thoth Tarot Companion (WordPress Plugin)',
        ) );
    }

    public function save_reading( WP_REST_Request $request ) {
        $id           = sanitize_text_field( $request->get_param( 'id' ) );
        $reading_data = $request->get_param( 'readingData' );

        if ( empty( $id ) || empty( $reading_data ) ) {
            return new WP_Error( 'missing_params', 'Missing reading ID or data', array( 'status' => 400 ) );
        }

        $transient_key = 'thoth_reading_' . $id;
        $payload       = array(
            'readingData' => $reading_data,
            'createdAt'   => current_time( 'mysql' ),
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

        return rest_ensure_response( array(
            'success' => true,
            'reading' => $reading,
        ) );
    }

    private function get_gemini_key() {
        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google']['authentication']['setting_name'] ) ) {
                $api_key = get_option( $connectors['google']['authentication']['setting_name'], '' );
                if ( ! empty( $api_key ) ) {
                    return $api_key;
                }
            }
        }
        $key = getenv( 'GEMINI_API_KEY' );
        if ( empty( $key ) && defined( 'GEMINI_API_KEY' ) ) {
            $key = GEMINI_API_KEY;
        }
        return $key;
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
            return new WP_Error( 'no_api_key', 'GEMINI_API_KEY is not configured.', array( 'status' => 500 ) );
        }

        $model = $this->get_gemini_model();
        $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
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
                'Content-Type' => 'application/json',
                'User-Agent'   => 'aistudio-build',
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

        $card_names = implode( ' -> ', array_map( function( $c ) { return isset( $c['name'] ) ? $c['name'] : ''; }, $pulled_cards ) );

        $prompt = sprintf(
            "Synthesize a rich, multi-chapter allegorical book narrative for this Tarot reading.\nQUERENT'S QUESTION: \"%s\"\nCARD SEQUENCE: %s\nNARRATIVE STYLE: %s\n\nOutput a JSON object with: title, stanzas, proseSynthesis, keyMotto, bookHook, bookChapters, style.\nRespond strictly in valid JSON format.",
            $question ? $question : 'Spiritual Awakening & Divine Alignment',
            $card_names,
            $style ? $style : 'crowleyan'
        );

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
