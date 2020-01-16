<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );


foreach ( glob(plugin_dir_path(__FILE__) . 'classes/*.php') as $file) {
    if (file_exists($file)) {
        include_once($file);
    } 
}

/*
Plugin Name: BLACKOUT FETCH POSTS PLUGIN
Description: Used to dynamically fetch and load posts. Pagination included.
Author: Helix
Version: 1.0.0
*/


/*
Function: load_blackout_plugin_scripts
Description: We load up some require javascript 
and setup some prefetched data from wordpress 
which we can use with our aforementioned javascripts.
*/
function load_blackout_plugin_scripts() {
    global $wp_query;
    if (is_category()) {
        wp_enqueue_script("vendors", plugins_url("js/vendors.js", __FILE__), array(), false, true);
        wp_enqueue_script("app", plugins_url("js/app.js", __FILE__), array(), false, true);


        $category = get_queried_object_id();
        $cat_parents = explode("/", get_category_parents($category));
        $parent = get_cat_ID($cat_parents[0]);
        $parent_category_url = get_category_link($parent);

        $categories =  get_terms( array (
            'taxonomy' => 'category',
            'child_of' => $parent,
            'hide_empty' => false)
        );

        wp_localize_script('app', 'blackout', array( 
            'ajaxurl' =>  admin_url("admin-ajax.php"),
            'current_page' => get_query_var('paged') ? get_query_var('paged') : 1,
            'max_num_pages' => $wp_query->max_num_pages,
            'category_id' => $category,
            'parent' => $parent,
            'parent_category_url' => $parent_category_url,
            'categories' => $categories,
            'query_vars' => json_encode( $wp_query->query )
        ));
    }

}

add_action("wp_enqueue_scripts", "load_blackout_plugin_scripts");

/*
Function: blackout_fetch_posts
Description: Handles our json responses for our guides index / archival page.
*/

function blackout_fetch_posts() {
    $query_vars['paged'] = $_GET['page'];
    $query_vars['posts_per_page'] = $_GET['posts_per_page'];
    $query_vars['post_status'] = 'publish';

    $mode = $_GET["mode"];
    
    $query_vars["category_name"] = implode($mode, $_GET['categories']);;
    $query_vars["category__and"] = $_GET["parent"];

    $query = new WP_Query( $query_vars );
    $GLOBALS["wp_query"] = $query;

    if (!$query->have_posts()) {
        $error = "No posts to send...";
        wp_send_json($error, 404);
        exit();
    }

    
    function grab($post) {
        if (isset($post)) {
            $image = wp_get_attachment_image_src(get_post_thumbnail_id($post->ID), "full");
            $post->featured = $image[0];
            $author = get_the_author_meta("display_name", $post->post_author);
            $post->author = $author;
            $url = get_permalink($post->ID);
            $post->url = $url;
        }
        return $post;
    }
    

    try {
        array_walk($query->posts, "grab");
        wp_send_json($query, 200);
    } catch (Exception $e) {
        wp_send_json($e->getMessage(), 500);
    } catch (InvalidArgumentException $e) {
        wp_send_json($e->getMessage, 404);
    }

    
    die();
}

add_action("wp_ajax_blackout_fetch_posts", "blackout_fetch_posts");
add_action("wp_ajax_nopriv_blackout_fetch_posts", "blackout_fetch_posts");



/*
Function: blackout_remove_wp_archives
Description: Redirects archival pages we don't need to a 404 page.
*/
function blackout_remove_wp_archives() {
    if (is_tag() || is_date() || is_author()) {
        global $wp_query;
        $wp_query->set_404();
    }
}

add_action("template_redirect", "blackout_remove_wp_archives");

function redirect_from_child_category() {
    if (is_category()) {
        $category_id = get_queried_object_id();
        $cat_parents = explode("/", get_category_parents($category_id));
        $parent = get_cat_ID($cat_parents[0]);
        $parent_category_url = get_category_link($parent);

        if ($category_id !== $parent) : 
            wp_safe_redirect($parent_category_url); 
            exit; 
        endif;
    }
}

add_action("template_redirect", "redirect_from_child_category");

?>