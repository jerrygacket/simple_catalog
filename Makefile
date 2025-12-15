install:
	mkdir -p dumps
	mkdir -p database
	mkdir -p app/logs
	mkdir -p logs/nginx
	chmod -R 777 database
	chmod -R 777 dumps
	chmod -R 777 reindexer_data
	chmod -R 777 logs
	chmod -R 777 app/logs

build:
	docker compose build

start:
	docker compose up -d

migrate:
	docker exec -t php81_fs php options.php
	docker exec -t php81_fs php products.php # takes time
	docker exec -t sync-service_fs ./sync-service load

all:
	make install
	make build
	make start
	make migrate
