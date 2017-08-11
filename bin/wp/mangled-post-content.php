<?php

/* mangled-post-content.php

The img-alt-text.php script mangled the content of some posts by adding extra line breaks after
it regenerated the post content to include alt text. This script identifies posts that may
have been affected so web coordinators may take corrective action.

User Story: https://trello.com/c/dgrdwoG0

Usage:

$ cd blogs
$ wp --debug eval-file bin/wp/mangled-post-content.php

 *
 * Display all errors.
 * See https://github.com/wp-cli/wp-cli/issues/706#issuecomment-156542189.
 */
ini_set( 'display_errors', 1 );
error_reporting(E_ERROR | E_WARNING | E_PARSE);

/*
 * Imports
 */
define('BIN_WP_DIR', dirname(__FILE__) . '/');
require_once(BIN_WP_DIR . 'lib/wp_cli_script.php');
require_once(BIN_WP_DIR . 'lib/chapman_post_model.php');

/*
 * Script Classs
 */
class MangledPostsScript extends WpCliScript {
    /*
     * Commands
     */
    public $commands = array(
        # Command => Description
        'usage' => 'Print this usage information',
        'scan' => 'Generate report identifying mangled posts across all sites',
        'sandbox' => 'Run any preset script method: useful for dev/troubleshooting'
    );

    /*
     * Constructor
     */
    function __construct($args, $wpdb) {
        parent::__construct($args, $wpdb);
        $this->output_dir = sprintf('%s/tmp/mangled-posts', $this->project_root);

        if ( ! file_exists($this->output_dir) ) {
            mkdir($this->output_dir, 0755, true);
        }
    }

    /*
     * Instance Methods
     */
    public function run() {
        if ( $this->command == 'scan' ) {
            return $this->generate_mangled_post_csv_files();
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

    private function generate_mangled_post_csv_files() {
        $report = array();
        $other_mangled_posts = array();

        foreach( $this->sites as $name => $site ) {
            $this->println(sprintf('Scanning site %s.', $site->name));
            switch_to_blog($site->id);

            $mangled_posts = ChapmanPost::mangled_by_alt_text_fix();
            $mangled_count = count($mangled_posts);
            $report[$name] = array('mangled posts' => $mangled_count, 'csv file' => null);

            if ( $mangled_count > 10 ) {
                $csv_file_name = $site->slug;
                $file_path = $this->generate_mangled_post_csv_file($csv_file_name, $mangled_posts);
                $this->println(sprintf('Generated file %s with %s posts listed.',
                                       $file_path,
                                       $mangled_count));
                $report[$name]['csv file'] = basename($file_path);
            }
            elseif ( $mangled_count > 1 ) {
                $other_mangled_posts = array_merge($other_mangled_posts, $mangled_posts);
                $this->println(sprintf('%s posts for site %s will be listed in other sites csv.',
                                       $mangled_count,
                                       $site->name));
                $report[$name]['csv file'] = 'See [other]';
            }
            else {
                $this->println(sprintf('No mangled posts for site %s.', $site->name));
            }
        }

        // Generate CSV file for sites with less than 10 mangled posts.
        $csv_file_name = 'other';
        $file_path = $this->generate_mangled_post_csv_file($csv_file_name, $other_mangled_posts);
        $this->println(sprintf('Generated file %s with %s posts listed.',
                                $file_path,
                                count($other_mangled_posts)));

        $report['other'] = array('mangled posts' => count($other_mangled_posts),
                                 'csv file' => basename($file_path));

        return print_r($report, 1);
    }

    private function generate_mangled_post_csv_file($name, $mangled_posts) {
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

        // Add header row.
        $data_rows = array(array_keys($data_map));

        // Convert mangled posts to data rows.
        $post_vars = array_values($data_map);
        foreach ( $mangled_posts as $post ) {
            $csv_data = array();

            foreach ( $post_vars as $var ) {
                $csv_data[] = $post->$var;
            }

            $data_rows[] = $csv_data;
        }

        // Write to csv.
        $output_path = sprintf('%s/%s.csv', $this->output_dir, $name);
        $this->write_to_csv($output_path, $data_rows);

        return $output_path;
    }
}

/*
 * Run Script
 */
$script = new MangledPostsScript($args, $wpdb);
$report = $script->run();
$script->println($report);
$script->print_errors();

// If this line doesn't print at end of run, probably a syntax error somewhere.
$script->println('Script complete.');
