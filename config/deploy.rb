# config valid only for Capistrano 3.1
lock '3.1.0'

############################################
# Setup WordPress
############################################

config = YAML::load_file('./config/config.yml')

set :local_domain, config['development_url']

############################################
# Setup project
############################################

set :application, "chap-press"
set :repo_url, "git@github.com:chapmanu/chap-press.git"
set :scm, :git

set :git_strategy, SubmoduleStrategy

############################################
# Setup Capistrano
############################################

set :log_level, :debug
set :use_sudo, false
set :pty, true

set :ssh_options, {
  forward_agent: true
}

set :keep_releases, 5

############################################
# Linked files and directories (symlinks)
############################################

set :linked_files, %w{wp-config.php}
set :linked_dirs, %w{content/uploads}

namespace :deploy do

  after 'starting', 'check_changes'

  desc "create WordPress files for symlinking"
  task :create_wp_files do
    on roles(:app) do
      execute :touch, "#{shared_path}/wp-config.php"
    end
  end

  after 'check:make_linked_dirs', :create_wp_files

  desc "Creates robots.txt for non-production envs"
  task :create_robots do
  	on roles(:app) do
  		if fetch(:stage) != :production then

		    io = StringIO.new('User-agent: *
Disallow: /')
		    upload! io, File.join(release_path, "public/robots.txt")
        execute :chmod, "644 #{release_path}/public/robots.txt"
      end
  	end
  end

  desc "Restart services"
  task :restart_services do
    on roles(:app) do
      execute "sudo /etc/init.d/php-fpm restart"
      execute "sudo /etc/init.d/nginx restart"
    end
  end

  after :finished, :create_robots
  after :finished, :restart_services
  after :finished, :clear_cache
  after :finishing, "deploy:cleanup"

  after :finished, 'prompt:complete' do 
    print_success("Deployment to #{fetch(:stage_domain)} complete!")
  end

  # Does not work for some reason
  # after :finished, 'prompt:clone' do
  #   invoke 'clone' if (fetch(:stage) != :production && confirm('Would you like to also re-clone production server data at this time?'))
  # end

end
