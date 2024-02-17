<?php
/*
Plugin Name: crawler
Description: A plugin to scrape data from a iscrapapp
Version: 1.0
Author: Azeem
*/

// Include SimpleHTMLDom library
require_once('simple_html_dom.php');

// Scraping Logic
function iscrapp_scrape_metals() {
    $base_url = 'https://iscrapapp.com/prices/?page_start=';
    $max_pages = 5; // Adjust the number of pages to scrape

    for ($page_num = 1; $page_num <= $max_pages; $page_num++) {
        $target_url = $base_url . $page_num;

        // Initialize cURL
        $ch = curl_init($target_url); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute and get content
        $response = curl_exec($ch);

        // Handle potential errors 
        if (curl_error($ch)) {
            error_log("Scraping Error: " . curl_error($ch));
            continue; // Move to the next page 
        }
        curl_close($ch);

        // Parse HTML and extract metal data
        $scraped_metals = parse_metals_from_html($response);

        // Insert or update data in the database
        foreach ($scraped_metals as $metal) {
            iscrapp_insert_or_update_metal($metal);
        }

        sleep(2); // Introduce a delay to be polite
    }
}

// Parse HTML and extract metal data
function parse_metals_from_html($html) {
    $scraped_metals = array();

    $dom = str_get_html($html);

    if ($dom) {
        foreach ($dom->find('.metal-table__metal') as $metal) {
            $name = $metal->find('.metal-table__metal-name', 0)->plaintext;
            $price = $metal->parent()->next_sibling()->children(1)->plaintext;
            $category = $metal->parent()->next_sibling()->next_sibling()->plaintext;

            $scraped_metals[] = array(
                'name' => $name,
                'price' => $price,
                'category' => $category
            );
        }

        $dom->clear();
    }

    return $scraped_metals;
}

// Data Storage
function iscrapp_insert_or_update_metal($metal) {
    $existing_post = get_page_by_title($metal['name'], OBJECT, 'metals');

    if ($existing_post) {
        // Update existing post
        $post_id = $existing_post->ID;
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $metal['name'],
        ));
    } else {
        // Create new post
        $post_id = wp_insert_post(array(
            'post_title' => $metal['name'],
            'post_type' => 'metals',
            'post_status' => 'publish',
        ));
    }

    if ($post_id) {
        update_post_meta($post_id, 'metal_price', $metal['price']);
        update_post_meta($post_id, 'metal_category', $metal['category']);
    }
}

// Cron Setup
add_action('wp_scrapyards_cron_update', 'iscrapp_scrape_metals');
if (!wp_next_scheduled('wp_scrapyards_cron_update')) {
    wp_schedule_event(time(), 'hourly', 'wp_scrapyards_cron_update'); 
}

// Basic Front-end Display (Shortcode)
add_shortcode('scrapyards_prices', 'scrapyards_display_prices');
function scrapyards_display_prices() {
    $output = '';
    $args = array(
        'post_type' => 'metals',
        'posts_per_page' => -1 // Get all metals
    );

    $metals_query = new WP_Query($args);
    if ($metals_query->have_posts()) {
        $output .= '<ul>';
        while ($metals_query->have_posts()) {
            $metals_query->the_post();
            $output .= '<li>';
            $output .= '<strong>' . get_the_title() . '</strong>: ';
            $output .= get_post_meta(get_the_ID(), 'metal_price', true);
            $output .= '</li>';
        }
        $output .= '</ul>';
    } else {
        $output = 'No metal prices found.';
    }

    wp_reset_postdata();
    return $output;
