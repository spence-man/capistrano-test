namespace :setup do

  config = YAML::load_file('config/config.yml')

  desc "Create wp-config.php"
  task :wp_config do

    # Ensure security keys exist
    Rake::Task["setup:generate_security_keys"].invoke if File.size?('wp-security-keys.php').nil?

    # Setup Variables
    wp_env         = 'development'
    wp_domain      = config['development_url']
    wp_domain_test = config['test_url']
    project_path   = Dir.pwd
    protocol       = 'http://'

    wp_debug = confirm('Enable WP Debug Mode? (Prints warnings and notices in browser)') ? true : false

    # Generate new salt
    secret_keys = `curl -s -k https://api.wordpress.org/secret-key/1.1/salt`

    # Get other credentials
    secrets = Hash.new
    secrets = YAML::load_file('config/secrets.yml') if File.exist?('config/secrets.yml')
    
    # Get database credentials
    database = Hash.new
    database_test = Hash.new
    if File.exist?('config/database.yml')
      database = YAML::load_file('config/database.yml')['local']
      database_test = YAML::load_file('config/database.yml')['test']
    else 
      database['host'] = 'localhost'
      database['database'] = 'development'
      database['username'] = ENV['MYSQL_USER']
      database['password'] = ENV['MYSQL_PASSWORD']

      database_test['host'] = 'localhost'
      database_test['database'] = 'test'
      database_test['username'] = ENV['MYSQL_USER']
      database_test['password'] = ENV['MYSQL_PASSWORD']
    end

    # Create wp-config.php
    db_config = ERB.new(File.read('config/templates/wp-config-development.php.erb')).result(binding)
    File.open("wp-config.php", 'w') {|f| f.write(db_config) }

    print_success('The WordPress config file has been created on your local machine :)')

    print_info (wp_debug) ? 'DEBUG MODE IS ENABLED; Squash those bugs!' : 'Debug mode is disabled; remember, hiding the bugs doesn\'t make them go away.....'

  end

  desc "Create or update the WP Security Keys"
  task :generate_security_keys do
    secret_keys = "<?php \n\n" +  `curl -s -k https://api.wordpress.org/secret-key/1.1/salt`
    File.open("wp-security-keys.php", 'w') {|f| f.write(secret_keys) }
    puts "The WP Security Keys have been changed. Existing user sessions have been invalidated and users must log in again."
  end

  # desc "Ensure wp-config.php exists"
  task :ensure_config_exists do
    if File.size?('wp-config.php').nil?
      Rake::Task["setup"].invoke
    else
      print_info('wp-config.php already exists.')
    end
  end

end

desc "Create all local config files"
task :setup => ["setup:wp_config"]
