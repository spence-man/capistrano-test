desc "Ensure that the database exists and if not, create one"
task :ensure_db_exists do
	tables = system 'wp db tables'
	data = system 'wp db query "SELECT COUNT(id) FROM wp_users"'

	print_info('No database present. Building one for you...')
	system 'wp db create' if !tables

	system 'cap production db:pull' if !data && confirm('Do you want to download production data now?')
		
	print_success('Database found.')
end