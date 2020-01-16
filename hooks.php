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

function iterate_over_blocks($block, $key) {
    if (isset($block)) {
        $title = str_replace(" ", "-", strtolower($block["title"]));

        $body = "<!---- START WP-GUIDES-BLOCK #".$key." ---->\n";
        $body .= "<div class='column is-full'>\n";
        $body .= "<div class='guide-block'>\n";
        $body .= "<div class='guide-head'>\n";
        $body .= "<h2 class='subtitle is-2'>\n";
        $body .= "<a href='#".$title."</a>\n";
        $body .= "</h2>\n";
        $body .= "</div>\n";
        $body .= "<div class='guide-body'>\n".$block["body"]."\n</div>\n";
        $body .= "</div>\n";
        $body .= "</div>\n";
        $body .= "<!---- END WP-GUIDES-BLOCK #".$key." ---->";
    }
    return $body;
}

function blackout_create_post() {
    if (isset($_REQUEST["nonce"])) {
        if (!wp_verify_nonce($_REQUEST['nonce'], "blackout")) {
            wp_send_json("Invalid nonce.", 400);
            exit("Invalid nonce.");
        }
    } else {
        wp_send_json("Invalid nonce.", 400);
        exit("Missing nonce.");
    }
        
    $blocks = $_POST["blocks"];
    
    $args["post_category"] = $_POST["categories"];
    $args["post_type"] = "post_type";
    $args["post_title"] = $_POST["title"];
    $args["post_name"] = $_POST["title"];
    $args["post_status"] = "publish";
    $args["content_filtered"] = implode("\n", $_POST["blocks"]);
    $args["comment_status"] = $_POST["comment_status"];
    
    if (sizeof($blocks)) {
        $body = implode("\n", array_map("iterate_over_blocks", $blocks, array_keys($blocks)));
    }
    
    // $args["post_content"] = wp_kses_post($body);
    
    // try {
    //     $post = wp_insert_postdata($args, true);
    //     add_post_meta($post, "hasTableOfContents", $_POST["enableTOC"], true);
    //     add_post_meta($postID, "numberOfBlocks", sizeof($blocks), true);
    //     wp_send_json("Post Created.", 200);
    // } catch (Exception $err) {
    //     wp_send_json($err->getMessage(), 400);
    // }

    wp_send_json($body, 200);
        
    die();
    
}
// add_action("wp_ajax_blackout_admin_test", "blackout_admin_test");
// add_action("wp_ajax_nopriv_blackout_admin_test", "blackout_admin_test");
add_action("wp_ajax_blackout_create_post", "blackout_create_post");
// add_action("wp_ajax_blackout_nopriv_generate_nonce", array($this, "blackout_generate_nonce"));


function manage_guides_page() {
    
    $plugin = new Guides(new GuidePage());
    $plugin->init();
}

add_action("plugins_loaded", "manage_guides_page");


function blackout_post_type_guide() {
    register_post_type("guide", 
        array(
            'labels' => array(
                'name' => __('Guides'),
                'singular' => __('Guide')
            ),
            'public' => false,
            'has_archive' => true,
            'rewrite' => array('slug' => 'guides/%guide%'),
            'supports' => array('title', 'excerpt', 'custom-fields', 'thumbnail'),
            'hierarchical' => true,
            )
        );
}

add_action("init", "blackout_post_type_guide");

function blackout_guide_post_link($post_link, $id = 0) {
    $post = get_post($id);
    if (is_object($post)) {
        $terms = wp_get_object_terms($post->ID, 'guide');
        if ($terms) {
            return str_replace('%guide%', $terms[0]->slug, $post_link);
        }
    }
    return $post_link;
}
add_filter("post_type_link", "blackout_guide_post_link");



/*
Function: load_blackout_plugin_scripts
Description: We load up some require javascript 
and setup some prefetched data from wordpress 
which we can use with our aforementioned javascripts.
*/


function load_blackout_plugin_scripts() {
    global $wp_query;
    if (is_category()) {
        wp_enqueue_script("vendors", "http://localhost:8080/js/chunk-vendors.js", array("jquery-ui-core", "jquery-ui-tabs"), "", true);
        wp_enqueue_script("app", "http://localhost:8080/js/app.js", array(), false, true);
        // wp_enqueue_script("vendors", plugins_url("js/vendors.js", __FILE__), array(), false, true);
        // wp_enqueue_script("app", plugins_url("js/app.js", __FILE__), array(), false, true);


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