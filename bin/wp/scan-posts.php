<?php

/* scan-posts.php

Scan posts across all blogs for malicious content.

Usage:

$ cd blogs
$ wp --debug eval-file bin/wp/scan-posts.php

 */
ini_set( 'display_errors', 1 );
error_reporting(E_ERROR | E_WARNING | E_PARSE);

// Global WordPress DB object.
global $wpdb;

/*
 * Models
 */
class SuspiciousPost {
    /*
     * Scope / Class Methods
     */
    static public function contains_suspicious_content($limit = -1) {
        global $wpdb;
        $suspicious_posts = array();

        // Query based on https://aw-snap.info/articles/spam-hack-wordpress.php.
        $sql_f = <<<SQL
SELECT *
 FROM %s
 WHERE
   post_type IN ('post', 'announcement', 'page', 'feature') AND
   post_status = 'publish'                                  AND
   (
     lower(post_content) LIKE '%%<script%%'         OR
     lower(post_content) LIKE '%%createelement%%'   OR
     lower(post_content) LIKE '%%.ru%%'             OR
     lower(post_content) LIKE '%%googleo%%'         OR
     lower(post_content) LIKE '%%cdata%%'
   )
 ORDER BY post_date DESC;
SQL;
        $sql = sprintf($sql_f, $wpdb->posts);
        $wp_posts = $wpdb->get_results($sql);

        foreach ( $wp_posts as $wp_post ) {
            $suspicious_posts[] = new SuspiciousPost($wp_post);
        }

        return $suspicious_posts;
    }

    /*
     * Constructor
     */
    function __construct($wp_post) {
        $this->wp_post = $wp_post;
        $this->content = $wp_post->post_content;
        $this->id = $wp_post->ID;
        $this->title = get_the_title($wp_post->ID);
        $this->blog_id = get_current_blog_id();
        $this->blog_path = get_blog_details($this->blog_id)->path;
        $this->date = $wp_post->post_date;
        $this->date_modified = $wp_post->post_modified;
    }
}

/*
 * Script Classs
 */
class ScanPostsScript {
    /*
     * Commands
     */
    public $commands = array(
        # Command => Description
        'usage' => 'Print this usage information',
        'scan' => 'Generate report identifying suspicious posts across all sites',
        'sandbox' => 'Run any preset script method: useful for dev/troubleshooting'
    );

    // Collects errors while script runs.
    private $errors = array();

    /*
     * Constructor
     */
    function __construct($args, $wpdb) {
        $this->db = $wpdb;
        $this->parse_command_line_args($args);
        $this->println(sprintf('Running command: %s', $this->command));
        $this->sites = $this->load_sites();

        // site_ids will be an array of strings.
        $this->site_ids = array();

        foreach ( $this->sites as $name => $blog ) {
            $this->site_ids[] = $blog->id;
        }
    }

    /*
     * Instance Methods
     */
    function run() {
        if ( $this->command == 'scan' ) {
            return $this->scan_all_sites();
        }
        elseif ( $this->command == 'sandbox' ) {
            // Put the sandbox method you wish to test here.
            $posts = $this->scan_site(1);
            var_dump($posts[0]);
        }
        else {
            return $this->usage();
        }
    }

    function scan_all_sites() {
        $all_posts = array();

        foreach ( $this->sites as $name => $blog ) {
            $site_posts = $this->scan_site($blog->id);
            $all_posts = array_merge($all_posts, $site_posts);
        }

        $this->println(sprintf("Found %s suspect posts in all sites.", count($all_posts)));

        $this->write_posts_to_csv($all_posts);
    }

    function scan_site($site_id) {
        switch_to_blog($site_id);
        $site_name = get_bloginfo();
        $report['site-name'] = $site_name;
        $this->println(sprintf("Scanning posts in %s (%s).", $site_name, $site_id));

        # Select all posts with <script> tag.
        $posts = SuspiciousPost::contains_suspicious_content();

        $this->println(sprintf("Found %s rows with suspect content.", count($posts)));
        return $posts;
    }

    function usage() {
        $usage_f = <<<EOB
Before running this script, it is recommended you backup your database:

$ wp db export /tmp/blogs-$(date +%%Y%%m%%d-%%H%%M%%S).sql

And then remove file when done.

Usage:

$ cd blogs
$ wp --debug eval-file %s <command>

Commands:
%s

EOB;

        return sprintf($usage_f, __FILE__, print_r($this->commands, 1));
    }

    function write_posts_to_csv($suspicious_posts) {
        $data_map = array(
            # Column Header => post var
            'Blog ID' => 'blog_id',
            'Blog Path' => 'blog_path',
            'Post ID' => 'id',
            'Post Date' => 'date',
            'Post Modified' => 'date_modified',
            'Post Title' => 'title',
            'Post Content' => 'content'
        );
        $csv_file_name = 'suspicious-posts-%s.csv';

        $fname = sprintf($csv_file_name, date('Ymd'));
        $output_path = sprintf('/tmp/%s', $fname);
        $headers = array_keys($data_map);
        $post_vars = array_values($data_map);

        $fp = fopen($output_path, 'w');
        fputcsv($fp, $headers, ',');

        foreach ( $suspicious_posts as $post ) {
            $csv_data = array();

            foreach ( $post_vars as $var ) {
                $csv_data[] = $post->$var;
            }

            fputcsv($fp, $csv_data, ',');
        }

        fclose($fp);
        $this->println(sprintf('CSV file for %s suspicious posts output to: %s',
                               count($suspicious_posts),
                               $output_path));
    }

    /*
     * Helper Methods
     */
    function parse_command_line_args($args) {
        $valid_commands = array_keys($this->commands);
        $this->command = array_shift($args);
        $this->subcommand = array_shift($args);

        if ( ! $this->command ) {
            $this->command = 'usage';
        }

        if ( ! in_array($this->command, $valid_commands) ) {
            $this->error(sprintf('Invalid command: %s. See usage.', $this->command));
        }
    }

    function load_sites() {
        // Return a hash of sites: name => site-object
        $loaded_sites = array();
        $wp_sites = get_sites();

        foreach ( $wp_sites as $i => $site_info ) {
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

    function error($message) {
        $this->errors[] = $message;
    }

    function println($message) {
        printf("%s\n", $message);
    }

    function print_errors() {
        if ( count($this->errors) ) {
            $report_f = "\nERRORS:\n%s";
            $this->println(sprintf($report_f, print_r($this->errors, 1)));
        }
    }
}

/*
 * Run Script
 */
$script = new ScanPostsScript($args, $wpdb);
$report = $script->run();
$script->println($report);
$script->print_errors();

// If this line doesn't print at end of run, probably a syntax error somewhere.
$script->println('Script complete.');

