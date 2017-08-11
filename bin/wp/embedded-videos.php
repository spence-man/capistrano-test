<?php
/*
identify posts that contain embedded videos

Usage:

$ cd blogs
$ wp --debug eval-file bin/wp/embedded-videos.php

*/

  define('BIN_WP_DIR', dirname(__FILE__) . '/');
  require_once(BIN_WP_DIR . 'lib/wp_cli_script.php');
  require_once(BIN_WP_DIR . 'lib/chapman_post_model.php');

  class EmbeddedVideosScript extends WpCliScript {

    public $search_terms = array(
      "post_content LIKE '%www.youtube.com/watch?%'",
      "post_content LIKE '%player.vimeo.com%'"
    );

    public $excluded_site_paths = array(
      '/huell-howser-archives/'
    );

    function __construct($args, $wpdb, $env) {
      parent::__construct($args, $wpdb);
      $this->sites = $this->load_sites();

      switch($env) {
        case "development":
          $this->domain = "http://localhost:9999";
        case "staging":
          $this->domain = "https://dev-blogs.chapman.edu";
        default:
          $this->domain = "https://blogs.chapman.edu";
      }
    }

    function run() {
      $posts = $this->scan_all_sites();
      $this->write_posts_to_csv($posts);
    }

    function load_sites() {
      $loaded_sites = array();
      $wp_sites = get_sites();

      foreach ( $wp_sites as $i => $site_info ) {
        if(in_array($site_info->path, $this->excluded_site_paths)){
          continue;
        }

        $site_id = $site_info->blog_id;
        switch_to_blog($site_id);

        $name = get_bloginfo();
        $site_info->name = $name;
        $site_info->id = $site_id;
        $site_info->posts_count = wp_count_posts()->publish;
        $loaded_sites[$name] = $site_info;
      }

      return $loaded_sites;
    }

    function scan_all_sites() {
      $all_posts = array();

      foreach($this->sites as $name => $blog) {
        $site_posts = $this->scan_site($blog->id);
        $all_posts = array_merge($all_posts, $site_posts);
      }

      return $all_posts;
    }

    function scan_site($site_id) {
      $site_name = get_bloginfo();
      $posts =  ChapmanPost::embedded_videos($site_id, $this->search_terms, $this->domain);
      $this->println(sprintf("Scanning posts in %s (%s).", $site_name, $site_id));
      return $posts;
    }

    function write_posts_to_csv($posts) {
      $data_map = array(
        # Column Header => post var
        'Blog ID'       => 'blog_id',
        'Blog Path'     => 'blog_path',
        'Post ID'       => 'id',
        'Post Title'    => 'title',
        'Post URL'      => 'post_url',
        'Post Date'     => 'date',
        'Post Modified' => 'date_modified'
      );
      $post_data = array(array_keys($data_map));
      $post_vars = array_values($data_map);
      $fname = sprintf('posts-with-videos-%s.csv', date('Ymd'));
      $output_path = sprintf('tmp/%s', $fname);

      foreach($posts as $post){
        $data = array();

        foreach($post_vars as $var) {
          $data[] = $post->$var;
        }

        $post_data[] = $data;
      }

      if(!is_dir('tmp')){ mkdir('tmp'); }
      $this->write_to_csv($output_path, $post_data);
      $this->println(sprintf('CSV file for posts with embedded videos output to: %s', $output_path));
    }
  }

  $script = new EmbeddedVideosScript($args, $wpdb, "production");
  $script->run();
?>