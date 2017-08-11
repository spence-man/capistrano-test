<?php

/*
 * Chapman Post Model
 * Wraps WP post objects to provide an interface more like a Rails ActiveRecord model.
 */
Class ChapmanPost {
    /*
     * Class Properties
     */
    public static $DEFAULT_QUERY_ARGS = array(
        'numberposts' => -1,
        'offset' => 0,
        'category' => 0,
        'orderby' => 'post_date',
        'order' => 'DESC',
        'meta_value' =>'',
        'post_type' => 'post',
        'post_status' => 'publish',
        'suppress_filters' => true
    );

    /*
     * Scope Methods
     */
    // Returns published posts as ChapmanPost objects. -1 means no limit (all posts).
    static public function published($limit = -1) {
        $published_posts = array();

        $wp_posts = ChapmanPost::wp_posts('publish', $limit);

        foreach ( $wp_posts as $wp_post ) {
            $published_posts[] = new ChapmanPost($wp_post);
        }

        // Would be nice if this returned a lazy iterator. But not sure how to do that in PHP.
        return $published_posts;
    }

    static public function mangled_by_alt_text_fix() {
        // Alt-text fix script inadvertantly added this string to all posts. So mangled posts
        // are expected to have this. It is possible this could produce false positives but
        // any posts with this string should be inspected anyway.
        $marker = '<?xml version="1.0" standalone="yes"?>';
        $mangled_posts = array();

        $wp_posts = ChapmanPost::search_wp_posts($marker);

        foreach ( $wp_posts as $wp_post ) {
            $mangled_posts[] = new ChapmanPost($wp_post);
        }

        // Would be nice if this returned a lazy iterator. But not sure how to do that in PHP.
        return $mangled_posts;
    }

    static public function search_wp_posts($needle, $status = 'publish', $limit = -1) {
        $args = ChapmanPost::$DEFAULT_QUERY_ARGS;
        $args['status'] = $status;
        $args['numberposts'] = $limit;
        $args['s'] = $needle;
        return get_posts($args);
    }

    static public function wp_posts_by_status($status = 'publish', $limit = -1) {
        $args = ChapmanPost::$DEFAULT_QUERY_ARGS;
        $args['status'] = $status;
        $args['numberposts'] = $limit;
        return get_posts($args);
    }

    // Returns Chapman posts with embedded videos
    static public function embedded_videos($site_id, $search_terms, $domain) {
        if(empty($search_terms)){ return null; }

        global $wpdb;
        switch_to_blog($site_id);
        $posts_with_videos = array();
        $search_str = implode(" OR ", $search_terms);
        $sql = "
            SELECT * FROM $wpdb->posts
                WHERE post_status = 'publish'
                AND post_type IN ('post', 'announcement', 'page', 'feature')
                AND ($search_str)
                ORDER BY post_date DESC;
        ";
        $wp_posts = $wpdb->get_results($sql);

        foreach ($wp_posts as $post) {
            $cu_post = new ChapmanPost($post);
            $cu_post->post_url = $cu_post->build_post_url($domain);
            $posts_with_videos[] = $cu_post;
        }

        return $posts_with_videos;
    }

    /*
     * Constructor
     */
    function __construct($wp_post) {
        $this->wp_post = $wp_post;
        $this->content = $wp_post->post_content;
        $this->id = $wp_post->ID;
        $this->title = get_the_title($wp_post->ID);
        $this->permalink = get_permalink($this->id);
        $this->slug = basename($this->permalink);
        $this->blog_id = get_current_blog_id();
        $this->blog_path = get_blog_details($this->blog_id)->path;
        $this->date = $wp_post->post_date;
        $this->date_modified = $wp_post->post_modified;
    }

    /*
     * Instance Methods
     */
    function build_post_url($domain) {
        return $domain . $this->blog_path . $this->slug;
    }
}