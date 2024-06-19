PORT ?= 8000

start:
	php -S 0.0.0.0:$(PORT) -t public public/index.php

install:
	composer install

lint:
	composer exec --verbose phpcs -- --standard=phpcs.xml public app

inspect:
	composer exec --verbose phpstan analyse -- -c phpstan.neon

create:
	./bin/create_tables

create_and_start: create start
