<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Custom escaping function to safely handle the '&' character
function custom_esc_attr($input) {
    $safe_input = esc_attr($input);
    return str_replace('&amp;', '&', $safe_input);
}


/* SHORTCODE TO DISPLAY JUST THE STREET ADDRESS
________________________________________________________________________*/

function display_community_address( $atts ) {
    $args = shortcode_atts( array(
        'post_id' => get_the_ID(),
    ), $atts );
    
    $address = get_field( 'map_community-location', $args['post_id'] );
    
    if ( ! empty( $address['address'] ) ) {
        $address_parts = explode( ',', $address['address'] );
        $street_address = trim( $address_parts[0] );
        return '<h6>' . esc_html( $street_address ) . '</h6>';
    }
    
    return '';
}
add_shortcode( 'community_address', 'display_community_address' );


/*  MLS PLUGIN - ESTATIK
________________________________________________________________________*/

libxml_use_internal_errors(true); // Suppress HTML parsing warnings globally for the following functions

function load_content_into_dom($content) {
    $dom = new DOMDocument();
    if (!empty(trim($content))) {
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    }
    return $dom;
}

// Adds commas to specified number strings such as sq ft and lot size
function add_commas_to_estatik_fields($content) {
   if (is_singular() && in_array('single-properties', get_body_class())) {
       $pattern = '/\d{1,3}(?=(\d{3})+(?!\d))/';
       $replacement = '$0,';
       $dom = load_content_into_dom($content);
       $xpath = new DOMXPath($dom);
       
       // Query for 'es-property-field--area'
       $elements = $xpath->query("//li[contains(@class, 'es-property-field--area')]/span[contains(@class, 'es-property-field__value')]");
       foreach ($elements as $element) {
           $text = $element->nodeValue;
           $modifiedText = preg_replace($pattern, $replacement, $text);
           $element->nodeValue = $modifiedText;
       }

       // Query for 'es-entity-field--lot_size'
       $elements = $xpath->query("//li[contains(@class, 'es-entity-field--lot_size')]/span[contains(@class, 'es-property-field__value')]");
       foreach ($elements as $element) {
           $text = $element->nodeValue;
           $modifiedText = preg_replace($pattern, $replacement, $text);
           $element->nodeValue = $modifiedText;
       }
       
       $content = $dom->saveHTML();
   }
   return $content;
}
add_filter('the_content', 'add_commas_to_estatik_fields', 9999);


// Removes commas from Agent Contact name field when named "list-agent"
// If at any point the field name changes, that class name needs updated
function remove_commas_from_estatik_fields($content) {
   if (is_singular() && in_array('single-properties', get_body_class())) {
       $pattern = '/,/';
       $replacement = '';
       $dom = load_content_into_dom($content);
       $xpath = new DOMXPath($dom);
       $elements = $xpath->query("//li[contains(@class, 'es-entity-field--list-agent')]/span[contains(@class, 'es-property-field__value')]");
       foreach ($elements as $element) {
           $text = $element->nodeValue;
           $modifiedText = preg_replace($pattern, $replacement, $text);
           $element->nodeValue = $modifiedText;
       }
       $content = $dom->saveHTML();
   }
   return $content;
}
add_filter('the_content', 'remove_commas_from_estatik_fields', 9999);


// Converts UPPERCASE to Title Case for the builders and community div
function convert_to_title_case($content) {
   if (is_singular() && in_array('single-properties', get_body_class())) {
       $pattern = '/\b\w+\b/';
       $dom = load_content_into_dom($content);
       $xpath = new DOMXPath($dom);
       $elements = $xpath->query("//li[contains(@class, 'es-entity-field--builder') or contains(@class, 'es-entity-field--subdivision')]/span[contains(@class, 'es-property-field__value')]");
       foreach ($elements as $element) {
           $text = $element->nodeValue;
           $modifiedText = preg_replace_callback($pattern, function ($matches) {
               return ucwords(strtolower($matches[0]));
           }, $text);
           $element->nodeValue = $modifiedText;
       }
       $content = $dom->saveHTML();
   }
   return $content;
}
add_filter('the_content', 'convert_to_title_case', 9999);


// Gets the Status Terms from MLS and adds them to the listings
// Shows the desired terms
function es312_property_badges_terms( $terms, $post_id ) {
	$terms = empty( $terms ) ? array() : $terms;
	$statuses = get_the_terms( $post_id, 'es_status' );

	if ( ! empty( $statuses ) ) {
		foreach ( $statuses as $status ) {
			// Check for both "Pending" and "Sold" statuses
			if ( $status->name === 'Pending' || $status->name === 'Sold' ) {
				$terms[] = $status;
			}
		}
	}

	return $terms;
}
add_filter( 'es_property_badges_terms', 'es312_property_badges_terms', 10, 2 );


// Adds a space after commas
// Does not add a space if there is already a space
// Does not add a space if the text is formatted like currency
// This is necessary because the string from MLS does not have spaces after commas
function add_spaces_after_commas_in_estatik_fields($content) {
   if (is_singular() && in_array('single-properties', get_body_class())) {
       $dom = new DOMDocument();
       libxml_use_internal_errors(true); 
       $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
       $xpath = new DOMXPath($dom);

       $divs = $xpath->query("//div[contains(@class, 'es-property_section') and not(contains(@class, 'es-property_section--video'))]");

       foreach ($divs as $div) {
           $spanElements = $xpath->query(".//span[contains(@class, 'es-property-field__value') and not(ancestor::iframe) and not(ancestor::script) and not(ancestor::style) and not(parent::li[contains(@class, 'es-entity-field--area')])]", $div);

           foreach ($spanElements as $span) {
               $text = $span->textContent;
               $currencyPattern = '/\$\d{1,3}(,\d{3})*(\.\d+)?/';
               
               if (!preg_match($currencyPattern, $text)) {
                   $text = str_replace(',', ', ', $text);
                   $text = preg_replace('/, +/', ', ', $text); // Ensure only one space after the comma.
               }

               $span->textContent = $text;
           }
       }
       $content = $dom->saveHTML();
   }
   return $content;
}
add_filter('the_content', 'add_spaces_after_commas_in_estatik_fields', 9999);


// Formats MLS phone numbers
function convert_phone_number_format($content) {
   if (is_singular() && in_array('single-properties', get_body_class())) {
       // Regular expression pattern to match phone number in format "123-456-7890"
       $pattern = '/(\d{3})-(\d{3})-(\d{4})/';

       // Replacement pattern for the desired format "(123) 456-7890"
       $replacement = '($1) $2-$3';

       // Convert phone numbers to desired format
       $content = preg_replace($pattern, $replacement, $content);
   }

   return $content;
}
add_filter('the_content', 'convert_phone_number_format', 9999);