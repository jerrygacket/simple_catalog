# Products catalog
Simple products catalog with quick filtering. 
Contains 
- two go-lang microservices, 
- one PHP app for backend and db deploy, 
- one Vue app for frontend.
## Languages
- PHP
- SQL
- JavaScript
- HTML
- CSS
- GO
## Technologies
- Docker
- Nginx
- Percona (SQL)
- PHP-FPM
- Reindexer (NoSQL)
- microservices
## Build & Deploy
```shell
git clone https://github.com/jerrygacket/simple_catalog.git
cd simple_catalog
make all
```
## Open
- Frontend: http://localhost:8088/
- Admin: http://localhost:8086/
- Product Search microservice: http://localhost:8087
- percona-reindexer Sync microservice: http://localhost:8085
- Reindexer: http://localhost:9088/face