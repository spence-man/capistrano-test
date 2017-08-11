<?php
/*
 * img-alt-text.php
 *
 * Script fixes alt-text for images in all existing posts to meet ADA accessibility
 * requirements. For more information, see this Trello card: https://trello.com/c/wFNGWuG3/
 *
 * Usage:
 * $ cd blogs
 * $ wp --debug eval-file bin/wp/img-alt-text.php usage
 *
 * Notes:
 * - Requires wp-cli.
 * - Follow pattern here: http://wordpress.stackexchange.com/a/90390/101432
 *
 */

/*
 * Display all errors.
 * See https://github.com/wp-cli/wp-cli/issues/706#issuecomment-156542189.
 */
ini_set( 'display_errors', 1 );
error_reporting(E_ERROR | E_WARNING | E_PARSE);

// Global WordPress DB object.
global $wpdb;

/*
 * Models
 */
Class CompliantPost {
    // Wrapper around WP post objects.

    /*
     * Scope Methods
     */
    // Returns published posts as ComplianPost objects. -1 means no limit (all posts).
    static public function published($limit = -1) {
        $compliant_posts = array();

        $wp_posts = CompliantPost::wp_posts('publish', $limit);

        foreach ( $wp_posts as $wp_post ) {
            $compliant_posts[] = new CompliantPost($wp_post);
        }

        // Would be nice if this returned a lazy iterator. But not sure how to do that in PHP.
        return $compliant_posts;
    }

    static public function wp_posts($status = 'publish', $limit = -1) {
        $args = array(
            'numberposts' => $limit,
            'offset' => 0,
            'category' => 0,
            'orderby' => 'post_date',
            'order' => 'DESC',
            'meta_value' =>'',
            'post_type' => 'post',
            'post_status' => $status,
            'suppress_filters' => true
        );
        return get_posts($args);
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

        // Wrap in div class to remove doc/html/body tags on rebuild. See:
        // https://stackoverflow.com/a/29499398/6763239
        // Encoding conversion fix found here:
        // https://davidwalsh.name/domdocument-utf8-problem
        // https://php.net/manual/en/class.domdocument.php
        $this->dom = new DOMDocument();
        $container = sprintf('<div class="tmp-post-wrap">%s</div>', $wp_post->post_content);
        $container = mb_convert_encoding($container, 'HTML-ENTITIES', 'UTF-8');
        $this->dom->loadHTML($container);

        // Media images must be fetch before embedded_images extract since EmbeddedImage
        // constructor expects CompliantPost with media_images.
        $this->media_images = $this->fetch_associated_media_images();
        $this->embedded_images = $this->extract_embedded_images_from_post_content();

        // Count updates.
        $this->updates_counter = array(
            'alt-updated' => 0,
            'alt-skipped' => 0,
            'alt-missing' => 0
        );

        // Log collections.
        $this->embedded_images_sans_alt = array();
        $this->errors = array();
    }

    /*
     * Instance Methods
     */
    function update_content_img_alt_attributes() {
        // Update alt attr in all content img tags to match Media Library value (if set).
        // Counts/logs changes and updates post content as a side-effect. Returns updated
        // post content.
        $post_updated = false;

        foreach ( $this->embedded_images as $embedded_image ) {
            $media_library_alt_text = $embedded_image->alt_text_from_media_library();

            if ( $media_library_alt_text ) {
                if ( $media_library_alt_text !== $embedded_image->alt_text ) {
                    $embedded_image->dom_node->setAttribute('alt', $media_library_alt_text);
                    $this->log_update('alt-updated');
                    $post_updated = true;
                }
                else {
                    $this->log_update('alt-skipped');
                }
            }
            else {
                $this->log_update('alt-skipped');

                if ( ! $embedded_image->alt_text ) {
                    $this->log_update('alt-missing');
                    $this->embedded_images_sans_alt[] = $embedded_image;
                }
            }
        }

        if ( $post_updated ) {
            $this->content = $this->save_content();
        }

        return $this->content;
    }

    function save_content() {
        // Updates img alt attribute in post content using technique described here:
        // http://stackoverflow.com/a/29499398/6763239
        $container = $this->dom->getElementsByTagName('div')->item(0);
        $container = $container->parentNode->removeChild($container);

        while ( $this->dom->firstChild) {
            $this->dom->removeChild($this->dom->firstChild);
        }

        while ( $container->firstChild ) {
            $this->dom->appendChild($container->firstChild);
        }

        // Rebuild post content HTML from DOM object.
        $post_content = $this->dom->saveXML();

        // Update post content.
        // https://codex.wordpress.org/Function_Reference/wp_update_post
        $return_error = true;
        $error = wp_update_post(array(
            'ID'           => $this->wp_post->ID,
            'post_content' => $post_content
        ), $return_error);

        if ( is_wp_error($error) ) {
           $errors = $error->get_error_messages();
            foreach ($errors as $error) {
                $this->log_error($error);
            }
        }

        return $post_content;
    }

    function log_update($type) {
        // All this does at present is increment update counter.
        $this->updates_counter[$type] += 1;
    }

    function log_error($error) {
        $this->errors[] = $error;
    }

    function counts($key=null) {
        $counts = array(
            'media-images' => count($this->media_images),
            'media-images-sans-alt' => 0,
            'embedded-images' => count($this->embedded_images),
            'embedded-images-sans-alt' => 0,
            'embedded-images-sans-media' => 0,
        );

        foreach ( $this->media_images as $media_image ) {
            if ( $media_image->alt_text == '' ) {
                $counts['media-images-sans-alt'] += 1;
            }
        }

        foreach ( $this->embedded_images as $embedded_image ) {
            if (  $embedded_image->alt_text == '' ) {
                $counts['embedded-images-sans-alt'] += 1;
            }

            if ( is_null($embedded_image->media_image) ) {
                $counts['embedded-images-sans-media'] += 1;
            }
        }

        if ( ! $key ) {
            return $counts;
        }
        else {
            return $counts[$key];
        }
    }

    function external_embedded_images() {
        $images = array();

        foreach ( $this->embedded_images as $embedded_image ) {
            if ( $embedded_image->has_external_src() ) {
                $images[] = $embedded_image;
            }
        }

        return $images;
    }

    function extract_embedded_images_from_post_content() {
        // Extract img nodes as a node collection (a traversable) and converts to an array to
        // be consistent with media_images.
        // http://stackoverflow.com/a/20861974/6763239
        $embedded_images = array();

        $img_nodes = $this->dom->getElementsByTagName('img');

        foreach ( $img_nodes as $img_node ) {
            $embedded_images[] = new EmbeddedImage($img_node, $this);
        }

        return $embedded_images;
    }

    function fetch_associated_media_images() {
        $media_images = array();

        $image_posts = get_children(array(
            'order' => 'ASC',
            'post_mime_type' => 'image',
            'post_parent' => $this->wp_post->ID,
            'post_status' => null,
            'post_type' => 'attachment'
        ));

        foreach ( $image_posts as $image_post ) {
            $media_images[] = new MediaLibraryImage($image_post);
        }

        return $media_images;
    }
}

class EmbeddedImage {
    /*
     * Constructor
     */
    function __construct($img_node, $compliant_post) {
        // http://php.net/manual/en/class.domelement.php
        $this->dom_node = $img_node;
        $this->post = $compliant_post;
        $this->alt_text = $img_node->getAttribute('alt');
        $this->src = $img_node->getAttribute('src');
        $this->html = $img_node->ownerDocument->saveXML($img_node);
        $this->media_image = $this->fetch_media_library_image();
    }

    /*
     * Instance Methods
     */
    function alt_text_from_media_library() {
        // Use in this order: alt-text setting, image title, image file name. Returns false
        // if no alt-text found.]
        if ( ! $this->media_image ) {
            return false;
        }

        if ( $this->media_image->alt_text ) {
            return $this->media_image->alt_text;
        }
        elseif ( $this->media_image->title ) {
            return $this->media_image->title;
        }
        elseif ( $this->media_image->file_name ) {
            return $this->media_image->file_name;
        }
        else {
            return false;
        }
    }

    function has_external_src() {
        // Upload images will have src values like this:
        // <site_url()>/wp-content/uploads/2017/01/IMG_0319-160x160.jpg
        $local_marker = sprintf('/wp-content/uploads', site_url());

        // src contains localhost marker?
        return strpos($this->src, $local_marker) === false;
    }

    function fetch_media_library_image() {
        // Looks for library media image (WP post record) associated with this embedded image.
        $post_id = $this->extract_post_id_from_class();

        if ( $post_id ) {
            $wp_image_post = get_post($post_id);

            if ( $wp_image_post ) {
                $media_image = new MediaLibraryImage($wp_image_post);
                return $media_image;
            }
        }

        // Rifle through post's media images looking for match.
        foreach ( $this->post->media_images as $media_image ) {
            // If embed_id is found in image src, we have a match.
            if ( strpos($this->src, $media_image->embed_id) !== false  ) {
                return $media_image;
            }
        }

        // If returned by this point, not found.
        return null;
    }

    function extract_post_id_from_class() {
        $post_id_prefix = 'wp-image-';
        $img_class_attr = $this->dom_node->getAttribute('class');

        if ( ! $img_class_attr ) {
            return null;
        }

        $img_classes = preg_split('/\s+/', trim($img_class_attr));

        foreach ( $img_classes as $img_class ) {
            // Does img class start with post ID prefix? If so, that should be our post image ID.
            if ( substr($img_class, 0, strlen($post_id_prefix)) === $post_id_prefix ) {
                $post_id = str_replace($post_id_prefix, '', $img_class);
                return (int) $post_id;
            }
        }

        return null;
    }
}

class MediaLibraryImage {
    /*
     * Constructor
     */
    function __construct($image_post) {
        $this->image_post = $image_post;
        $this->alt_text = get_post_meta($image_post->ID, '_wp_attachment_image_alt', true);
        $this->title = $image_post->title;
        $this->file_name = $image_post->post_name;
        $this->embed_id = $this->extract_image_guid_nub($image_post->guid);
    }

    /*
     * Instance Methods
     */
    function extract_image_guid_nub($guid) {
        // This function will slice and dice image post guid value it so that it can be used to
        // associate an media library image (post) with the image embedded in a post's post_content.
        //
        // Example values:
        // guid: https://blogs.chapman.edu/wp-content/uploads/2017/01/IMG_0319.jpg
        // src:  http://localhost:9999/wp-content/uploads/2017/01/IMG_0319-160x160.jpg
        //
        // Given the guid above, it will return: '2017/01/IMG_0319'
        $split = explode('uploads', $guid);

        if ( count($split) < 2 ) {
            return null;
        }
        $img_path = $split[1];

        // Break extension (e.g. .jpg) off path.
        $split = explode('.', $img_path, -1);
        $embed_id = $split[0];

        return $embed_id;
    }
}

/*
 * Script Classs
 */
class ImgAltTextScript {
    /*
     * Commands
     */
    public $commands = array(
        # Command => Description
        'usage' => 'Print this usage information',
        'fix' => 'Run fix to update alt-text in all posts to match media library value',
        'sites' => 'List all blog sites associated with Blogs website',
        'survey' => 'Survey data to help assess scope of issue',
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

        // TODO: Create a ChapmanBlogSite class.
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

    /*
     * Instance Methods
     */
    function run() {
        if ( $this->command == 'fix' ) {
            return $this->fix();
        }
        elseif ( $this->command == 'sites' ) {
            return $this->list_sites();
        }
        elseif ( $this->command == 'survey' ) {
            return $this->survey_data();
        }
        elseif ( $this->command == 'sandbox' ) {
            // Put the sandbox method you wish to test here.
            return $this->test_post_formatting();
        }
        else {
            return $this->usage();
        }
    }

    /*
     * Command Methods
     */
    function fix() {
        // Fixes img alt texts in published posts for 1 or more sites depending on subcommand.
        $max_small_site_posts = 1000;

        $subcommands = array(
            '<Site ID>' => 'Run fix for one site. Use sites command to list sites with IDs.',
            'small' => sprintf('Run fix against sites with less than %s posts.',
                               $max_small_site_posts),
            'all' => 'Run fix against all sites. This may take a while.',
            'help' => 'Prints this help message.'
        );
        $valid_subcommands = array_keys($subcommands);
        $help = sprintf("\nValid Subcommands for fix:\n%s", print_r($subcommands, 1));

        if ( is_null($this->subcommand) ) {
            $this->subcommand = 'help';
        }

        // Run subcommand.
        // Fix individual site.
        if ( in_array($this->subcommand, $this->site_ids) ) {
            $site_id = $this->subcommand;
            $report = $this->fix_img_alt_text_for_site($site_id);
            $this->img_sans_alt_to_csv($report['images-sans-alt']);
            return print_r($report['counts'], 1);
        }
        // Fix group of sites (small sites or all).
        elseif ( in_array($this->subcommand, array('all', 'small')) ) {
            $max_posts = ($this->subcommand == 'all') ? 10000 : $max_small_site_posts;
            $reports = array();
            $images_sans_alt = array();

            foreach ( $this->sites as $name => $blog ) {
                // Per product owner, skip Blog 18 (Schmid)
                if ( $blog->id == 18 ) {
                    $this->println(sprintf('Skipping Blog #%s: %s', $blog->id, $name));
                }

                if ( $blog->posts_count < $max_posts ) {
                    $report = $this->fix_img_alt_text_for_site($blog->id);
                    $report['counts']['name'] = $name;
                    $reports[$name] = $report['counts'];
                    $images_sans_alt += $report['images-sans-alt'];
                }
                else {
                    $this->println(sprintf('Skipping blog %s: more than %s posts (%s).',
                                           $name,
                                           $max_posts,
                                           $blog->posts_count));
                }
            }

            $this->img_sans_alt_to_csv($images_sans_alt);
            $this->println('FINAL REPORT:');
            return print_r($reports, 1);
        // Help.
        }
        elseif ( $this->subcommand == 'help' ) {
            return $help;
        }
        // Invalid command -> help.
        else {
            $this->error(sprintf('Invalid subcommand for fix: %s', $this->subcommand));
            return $help;
        }
    }

    function list_sites() {
        $report_lines = array();
        $header_f = '%3s: %-32s %-40s   Published Posts';
        $line_f   = '%3s: %-32s %-40s   %5s posts';

        // Header
        $report_lines[] = "\nBlogs Sites";
        $report_lines[] = sprintf($header_f, 'ID', 'Path', 'Site Name');

        foreach ( $this->sites as $name => $blog ) {
            $this->println(sprintf('Counting posts for site: %s', $name));
            switch_to_blog($blog->id);
            $title = (strlen($name) > 40) ? substr($name,0,37).'...' : $name;
            $report_lines[] = sprintf($line_f, $blog->id, $blog->path, $title, $blog->posts_count);
        }

        return implode("\n", $report_lines);
    }

    function survey_data() {
        // Survey each multisite blog.
        $surveys = array();
        foreach ( $this->sites as $name => $blog ) {
            $this->println(sprintf("Surveying Blog #%s: %s.", $blog->id, $blog->name));
            $survey = $this->survey_site($blog->id);
            $surveys[$blog->name] = $survey;
        }

        // Compile meta survey.
        $posts = array();
        $site_counts = array();
        $meta_survey = array(
            'posts'                         => 0,
            'media-images'                  => 0,
            'media-images-sans-alt'         => 0,
            'embedded-images'               => 0,
            'embedded-images-sans-alt'      => 0,
            'embedded-images-sans-media'    => 0,
            'embedded-images-external'      => 0
        );
        foreach ( $surveys as $name => $survey ) {
            $posts += $survey['posts'];
            $counts = $survey['counts'];
            $meta_survey['posts'] += $counts['posts'];
            $meta_survey['media-images'] += $counts['media-images'];
            $meta_survey['media-images-sans-alt'] += $counts['media-images-sans-alt'];
            $meta_survey['embedded-images'] += $counts['embedded-images'];
            $meta_survey['embedded-images-sans-alt'] += $counts['embedded-images-sans-alt'];
            $meta_survey['embedded-images-sans-media'] += $counts['embedded-images-sans-media'];
            $site_counts[$name] = $counts;
        }

        // Count non-uploaded images
        foreach ( $posts as $post ) {
            $meta_survey['embedded-images-external'] += count($post->external_embedded_images());
        }

        $report_f = <<<EOB
SITES:
%s

META:
%s
EOB;
        return sprintf($report_f, print_r($site_counts, 1), print_r($meta_survey, 1));
    }

    function usage() {
        $usage_f = <<<EOB
Before running this script, it is recommended you backup your database:

$ wp db export /tmp/blogs-$(date +%%Y%%m%%d-%%H%%M%%S).sql

And then remove file when done.

Usage:

$ cd blogs
$ wp --debug eval-file bin/wp/img-alt-text.php <command>

Commands:
%s

EOB;

        return sprintf($usage_f, print_r($this->commands, 1));
    }

    /*
     * Sandbox Test Methods
     */
    function test_img_external_source() {
        $links = array(
            'external' => array(),
            'internal' => array()
        );
        $posts = CompliantPost::published();

        foreach ( $posts as $post ) {
            foreach ( $post->embedded_images as $embedded_image ) {
                if ( $embedded_image->has_external_src() ) {
                    $links['external'][] = $embedded_image->src;
                }
                else {
                    $links['internal'][] = $embedded_image->src;
                }
            }
        }

        var_dump($links);
    }

    function test_media_library_association() {
        $posts = CompliantPost::published();

        foreach ( $posts as $post ) {
            foreach ( $post->embedded_images as $embedded_image ) {
                if ( $embedded_image->media_image ) {
                    $this->println(sprintf('Found media image with alt-text: %s',
                                           $embedded_image->media_image->alt_text));
                }
                else {
                    var_dump($embedded_image->media_image);
                }
            }
        }
    }

    function compare_img_alt_text_to_media_library() {
        $posts = CompliantPost::published();

        foreach ( $posts as $post ) {
            foreach ( $post->embedded_images as $embedded_image ) {
                var_dump(array(
                    $embedded_image->alt_text,
                    $embedded_image->alt_text_from_library()
                ));
            }
        }
    }

    function test_html_fix() {
        // Post must be able to identify EmbeddedImage object in its content.
        $posts = CompliantPost::published();
        $first_post = $posts[0];
        $first_image = $first_post->embedded_images[0];

        // Test
        $content_before = $first_post->wp_post->post_content;
        $updated_content = $first_post->update_content_img_alt_attributes();

        // Dump output.
        var_dump(array(
            'before' => $content_before,
            'after' => $updated_content
        ));
        var_dump($first_post->errors);
        var_dump($first_post->updates_counter);
    }

    function test_post_formatting(){
        switch_to_blog(10);

        # this post was identified as having broken formatting
        $post = get_post(52665);

        $complient_post = new CompliantPost($post);
        $post_content = $complient_post->wp_post->post_content;
        $post_content_before = $post_content;

        #mimick save method without actual post update
        $container = $complient_post->dom->getElementsByTagName('div')->item(0);
        $container = $container->parentNode->removeChild($container);

        while ( $complient_post->dom->firstChild) {
            $complient_post->dom->removeChild($complient_post->dom->firstChild);
        }

        while ( $container->firstChild ) {
            $complient_post->dom->appendChild($container->firstChild);
        }

        $updated_post_content = $complient_post->dom->saveXML();

        $post_content = $updated_post_content;
        $post_content_after = $post_content;

        var_dump(array(
            'Content before' => $post_content_before,
            'Content after' => $post_content_after
        ));
    }

    function test_encoding_conversion() {
        // Source: https://davidwalsh.name/domdocument-utf8-problem
        $html = "<p>“It’s all been a happy accident, I didn’t plan my career this way”" .
                " – Erik Linstead.</p>";
        $wrapped_html = sprintf('<div class="tmp-post-wrap">%s</div>', $html);

        $doc = new DOMDocument();

        $doc->loadHTML($wrapped_html);
        $untreated_html = $doc->saveXML();

        $converted_html = mb_convert_encoding($wrapped_html, 'HTML-ENTITIES', 'UTF-8');
        $doc->loadHTML($converted_html);
        $treated_html = $doc->saveXML();

        var_dump(array(
            'before'    => $html,
            'untreated' => $untreated_html,
            'treated'   => $treated_html,
            'strcmp'    => strcmp($untreated_html, $treated_html)
        ));
    }

    function test_get_sites() {
        var_dump(get_sites());
    }

    /*
     * Helper Methods
     */
    function fix_img_alt_text_for_site($site_id) {
        $report = array(
            'site_id' => $site_id,
            'site-name' => '',
            'images-sans-alt' => array(),
            'counts' => array(
                'alt-updated' => 0,
                'alt-skipped' => 0,
                'alt-missing' => 0
            )
        );
        $images_sans_alt = array();

        switch_to_blog($site_id);
        $site_name = get_bloginfo();
        $report['site-name'] = $site_name;
        $this->println(sprintf("Updating alt text for posts in %s (%s).", $site_name, $site_id));

        // Update posts.
        $posts = CompliantPost::published();

        foreach ( $posts as $post ) {
            $post->update_content_img_alt_attributes();

            // Update site report.
            $report['images-sans-alt'] = array_merge($report['images-sans-alt'],
                                                     $post->embedded_images_sans_alt);
            $report['counts']['alt-updated'] += $post->updates_counter['alt-updated'];
            $report['counts']['alt-skipped'] += $post->updates_counter['alt-skipped'];
            $report['counts']['alt-missing'] += $post->updates_counter['alt-missing'];

            // Report errors.
            if ( count($post->errors) > 0 ) {
                $key = sprintf("Errors reported for post #%s in site %s:", $post->id, $site_name);
                $this->errors[] = array($key => $post->errors);
            }

            $this->println(sprintf("Post %s processed.", get_the_title($post->id)));
        }

        // Output site report to screen.
        $this->println(sprintf("Site update complete for %s.", $site_name));
        print_r($report['counts']);
        $this->println('------');

        // Return report.
        return $report;
    }

    function survey_site($site_id) {
        switch_to_blog($site_id);

        $survey = array(
            'posts' => array(),
            'counts' => array()
        );

        $counts = array(
            'posts'                         => 0,
            'media-images'                  => 0,
            'media-images-sans-alt'         => 0,
            'embedded-images'               => 0,
            'embedded-images-sans-alt'      => 0,
            'embedded-images-sans-media'    => 0
        );

        $posts = CompliantPost::published();
        $survey['posts'] = $posts;

        // Update counts.
        foreach ( $posts as $post ) {
            $counts['posts'] += 1;
            $counts['media-images'] += $post->counts('media-images');
            $counts['media-images-sans-alt'] += $post->counts('media-images-sans-alt');
            $counts['embedded-images'] += $post->counts('embedded-images');
            $counts['embedded-images-sans-alt'] += $post->counts('embedded-images-sans-alt');
            $counts['embedded-images-sans-media'] += $post->counts('embedded-images-sans-media');
        }

        $survey['counts'] = $counts;
        return $survey;
    }

    function img_sans_alt_to_csv($embedded_images) {
        // Outputs info for img tags missing alt attr to CSV file.
        $fname = sprintf('blogs-images-sans-alt-%s-%s-%s.csv',
                         date('Ymd'), $this->command, $this->subcommand);
        $output_path = sprintf('/tmp/%s', $fname);
        $headers = array('Blog ID', 'Blog Path', 'Post ID', 'Post Title', 'img src');

        $fp = fopen($output_path, 'w');
        fputcsv($fp, $headers, ',');

        foreach ( $embedded_images as $embedded_image ) {
            $csv_data = array($embedded_image->post->blog_id,
                              $embedded_image->post->blog_path,
                              $embedded_image->post->id,
                              $embedded_image->post->title,
                              $embedded_image->src);
            fputcsv($fp, $csv_data, ',');
        }

        fclose($fp);
        $this->println(sprintf('CSV file for %s images missing alt output to: %s',
                               count($embedded_images),
                               $output_path));
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
$script = new ImgAltTextScript($args, $wpdb);
$report = $script->run();
$script->println($report);
$script->print_errors();

// If this line doesn't print at end of run, probably a syntax error somewhere.
$script->println('Script complete.');
