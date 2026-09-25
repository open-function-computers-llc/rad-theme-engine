-include .env
export

test:
	WP_TESTS_CONFIG=$(WP_TESTS_CONFIG) ./vendor/bin/phpunit

coverage:
	WP_TESTS_CONFIG=$(WP_TESTS_CONFIG) XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html=report

watch:
	find src tests -name '*.php' | entr make test
