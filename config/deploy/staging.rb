############################################
# Staging Server
############################################

set :stage, :staging
set :stage_domain, "localhost:80"
server "localhost:80", user: "chap-press", roles: %w{web app db}
set :deploy_to, "/usr/share/nginx/chap-press"


############################################
# Setup Git
############################################

# The git branch for staging
def current_git_branch
  branch = `git symbolic-ref HEAD 2> /dev/null`.strip.gsub(/^refs\/heads\//, '')
  puts "Deploying branch #{branch}"
  branch
end

# Set the deploy branch to the current branch
set :branch, current_git_branch
