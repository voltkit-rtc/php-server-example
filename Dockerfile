FROM php:8.3-cli-alpine
WORKDIR /app
COPY src ./src
COPY public ./public
COPY test ./test
USER nobody
ENV PORT=7000 HOST=0.0.0.0
EXPOSE 7000
HEALTHCHECK --interval=15s --timeout=5s CMD php -r 'exit(str_contains((string)@file_get_contents("http://127.0.0.1:".(getenv("PORT")?:7000)."/health"), "ok") ? 0 : 1);'
# The built-in server is fine for a lab; use php-fpm behind nginx for real traffic.
CMD ["sh", "-c", "php -S ${HOST}:${PORT} public/index.php"]
