<?php
/*
 * WP CLI Script
 * Base class for wp-cli scripts.
 *
 * Usage
 *
 * define( 'BIN_WP_DIR', dirname(__FILE__).'/' );
 * require_once(BIN_WP_DIR . '/lib/wp_cli_script.php');
 *
 * class MyScript extends WpCliScript {}
 */
class WpCliScript {
    /*
     * Commands
     * You'll want to override this in your child class.
     */
    public $commands = array(
        # Command => Description
        'usage' => 'Print this usage information'
    );

    // These are set by parse_command_line_args method.
    public $command = '';
    public $subcommand = '';

    // Return by load_sites method.
    public $sites = array();
    public $site_ids = array();

    // Collects errors while script runs.
    public $errors = array();

    // Other properties
    public $project_root = '';

    /*
     * Constructor
     */
    function __construct($args, $wpdb) {
        $this->db = $wpdb;
        $this->turn_on_error_reporting();
        $this->parse_command_line_args($args);
        $this->println(sprintf('Running command: %s', $this->command));
        $this->load_sites();
        $this->project_root = dirname(dirname(dirname(dirname(__FILE__))));
    }

    /*
     * Public Instance Methods
     */
    public function run() {
        throw new Exception('TODO: Override this method.');
    }

    public function turn_on_error_reporting() {
        // Display all errors.
        // See https://github.com/wp-cli/wp-cli/issues/706#issuecomment-156542189.
        ini_set( 'display_errors', 1 );
        error_reporting(E_ERROR | E_WARNING | E_PARSE);
        return null;
    }

    public function error($message) {
        $this->errors[] = $message;
        return null;
    }

    public function println($message) {
        printf("%s\n", $message);
        return null;
    }

    public function print_errors() {
        if ( count($this->errors) ) {
            $report_f = "\nERRORS:\n%s";
            $this->println(sprintf($report_f, print_r($this->errors, 1)));
        }
        return null;
    }

    public function write_to_csv($output_path, $data_rows) {
        // Writes CSV file to output_path. $data_rows should be an array of data arrays.
        $fp = fopen($output_path, 'w');

        foreach ( $data_rows as $row ) {
            fputcsv($fp, $row, ',');
        }

        fclose($fp);
        return null;
    }

    /*
     * Protected Instance Methods
     */
    protected function parse_command_line_args($args) {
        $valid_commands = array_keys($this->commands);
        $this->command = array_shift($args);
        $this->subcommand = array_shift($args);

        if ( ! $this->command ) {
            $this->command = 'usage';
        }

        if ( ! in_array($this->command, $valid_commands) ) {
            $this->error(sprintf('Invalid command: %s. See usage.', $this->command));
        }

        return null;
    }

    protected function load_sites($include_deleted = 0) {
        // sites will be a hash of sites: name => site-object
        $this->sites = array();

        // site_ids will be an array of strings.
        $this->site_ids = array();

        $wp_sites = get_sites(['deleted' => $include_deleted]);

        foreach ( $wp_sites as $i => $site_info ) {
            $site_id = $site_info->blog_id;
            switch_to_blog($site_id);
            $name = get_bloginfo();
            $site_info->name = trim($name);
            $site_info->id = $site_id;
            $site_info->slug = sanitize_title_with_dashes($site_info->name);
            $site_info->posts_count = wp_count_posts()->publish;
            $this->sites[$name] = $site_info;
        }

        foreach ( $this->sites as $name => $blog ) {
            $this->site_ids[] = $blog->id;
        }

        return null;
    }

    protected function usage() {
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
}
