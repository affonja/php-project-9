install:
	composer install

validate:
	composer validate

inspect:
	composer exec --verbose phpstan analyse -- -c phpstan.neon

PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
