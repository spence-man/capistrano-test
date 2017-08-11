
desc "Starts a web server for development"
task :serve => 'setup:ensure_config_exists' do

  config = YAML::load_file('config/config.yml')

  # Start php server
  exec "php -S #{config['development_url']} -c config/local-php.ini -t public/ lib/router.php"

end