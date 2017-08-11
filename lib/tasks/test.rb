require 'selenium-connect'

desc "Run tests to make sure stuff works great every time!"
task :test => 'setup:ensure_config_exists' do 

  config = YAML::load_file('config/config.yml')

  # Start web server
  pid = Process.spawn("php -S #{config['test_url']} -t public/ tests/router.php")
  puts "Started a PHP web server with PID #{pid}"

  # Selenium Config
  config = SeleniumConnect::Configuration.new browser: 'firefox'

  # Start the selenium server
  sc = SeleniumConnect.start config

  # Run tests
  tests_passed = system 'phpunit'

  print "Cleaning up after the test suite... "

  # Close the selenium server
  sc.finish

  # Close the PHP server
  Process.kill "TERM", pid
  Process.wait pid

  puts "Done!"

  exit 1 if !tests_passed

end
