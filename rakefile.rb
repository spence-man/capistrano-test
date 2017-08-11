
require 'yaml'
require 'erb'
require './lib/helpers.rb'
include Helpers

# Include all the tasks
Dir["lib/tasks/*.rb"].each {|file| import file }
