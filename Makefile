<<<<<<< HEAD
PORT ?= 8000

start:
	php -S 0.0.0.0:$(PORT) -t public public/index.php

setup:
	composer install

compose:
	docker-compose up

compose-bash:
	docker-compose run web bash

compose-setup: compose-build
	docker-compose run web make setup

compose-build:
	docker-compose build

compose-down:
	docker-compose down -v
=======
install:
	composer install

validate:
	composer validate

inspect:
	composer exec --verbose phpstan analyse -- -c phpstan.neon

PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
>>>>>>> 6744e6b81a027ab77b3d3cdf5ed8ae53c549210f
