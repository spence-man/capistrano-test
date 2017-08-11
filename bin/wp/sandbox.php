<?php

/* sandbox.php

Use this script to build and test your wp-cli eval-file piecemeal.

For some complications is using eval-file, see:

http://wordpress.stackexchange.com/questions/257263/

Usage:

$ cd blogs
$ wp --debug eval-file bin/wp/sandbox.php

 */
/*
 * Display all errors.
 * See https://github.com/wp-cli/wp-cli/issues/706#issuecomment-156542189.
 */
ini_set( 'display_errors', 1 );
error_reporting(E_ERROR | E_WARNING | E_PARSE);

/*
 * Imports
 */
define( 'BIN_WP_DIR', dirname(__FILE__).'/' );
require_once(BIN_WP_DIR . '/lib/chapman_post_model.php');

/*
 * Global WP DB Object
 */
global $wpdb;

// Set function you want to test here.
function sandbox() {
    survey_themes();
}

// Try to figure out which themes are being used by Blogs multisite.
function survey_themes() {
    global $wpdb;
    $sites = wp_get_sites(['deleted' => 0]);
    $themes = array();

    foreach( $sites as $i => $site ) {
        switch_to_blog($site['blog_id']);
        $site_name = get_bloginfo();
        println(sprintf("Surveying themes for: %s", $site_name));

        $theme = wp_get_theme();
        $theme_name = $theme->get('Name');
        $theme_parent = $theme->parent();

        $site_theme = array(
            'site' => $site_name,
            'theme' => $theme_name,
        );

        if ( isset($themes[$theme_name]) ) {
            $themes[$theme_name][] = $site_name;
        }
        else {
            $themes[$theme_name] = array($site_name);
        }

        if ( $theme_parent ) {
            $parent_name = $theme_parent->get('Name') . '*';
            if ( isset($themes[$parent_name]) ) {
                $themes[$parent_name][] = $site_name;
            }
            else {
                $themes[$parent_name] = array($site_name);
            }

            $site_theme['parent'] = $parent_name;
        }

        print_r($site_theme);
    }

    print_r($themes);
    println("\nHere are the active themes:\n");
    print_r(array_keys($themes));
}

// Collect info on each blog that is part of Blogs multisite.
function walk_multisite() {
    $sites = wp_get_sites();
    var_dump($sites);

    foreach ( $sites as $i => $site ) {
        switch_to_blog($site['blog_id']);
        $site_name = get_bloginfo();
        println(sprintf("Switching to blog %s (%s).", $site_name, $site['blog_id']));
        restore_current_blog();
    }

    $site_name = get_bloginfo();
    println(sprintf('Ended on blog: %s.', $site_name));
}

// Count mangled posts using new ChapmanPost model.
function mangled_posts() {
    global $wpdb;
    $sites = wp_get_sites(['deleted' => 0]);
    $mangled_count = 0;

    foreach( $sites as $i => $site ) {
        switch_to_blog($site['blog_id']);
        $mangled_posts = ChapmanPost::mangled_by_alt_text_fix();
        $mangled_count += count($mangled_posts);

        $report = array(
            'site-id' => $site['blog_id'],
            'site-name' => get_bloginfo(),
            'mangled_count' => count($mangled_posts)
        );
        print_r($report);
    }

    println(sprintf('Number mangled posts: %s', $mangled_count));
}

// Count number of posts whose content may have been broken by the image-alt-text.php script
function broken_post_count() {
    global $wpdb;
    $sites = wp_get_sites(['deleted' => 0]);
    $total_broken_count = 0;

    foreach( $sites as $i => $site ) {
        switch_to_blog($site['blog_id']);

        // this tag is inserted into the post content by the image-alt-text script if there is an embedded image in the post
        $search_str = '<?xml version="1.0" standalone="yes"?>';

        $sql = "
            SELECT COUNT(id) FROM $wpdb->posts
            WHERE post_status = 'publish'
            AND   post_type = 'post'
            AND   post_content LIKE '%$search_str%'
        ";

        $broken_count = $wpdb->get_var($sql);

        $report = array(
            'site-id' => $site['blog_id'],
            'site-name' => get_bloginfo(),
            'broken-post-count' => $broken_count,
        );

        $total_broken_count += $report['broken-post-count'];
        print_r($report);
    }

    println(sprintf('Number of broken posts: %s', $total_broken_count));
}

// Helper Methods
function println($message) {
    printf("%s\n", $message);
}

// Run Script
sandbox();
echo "Script complete.\n";