# Box Chat
A simple web-based chat application built with PHP and Bootstrap.

### Run locally by running a docker container with PHP and Apache

- Install Docker
- Run the following command in the terminal from the project directory:
```bash
docker run -d -p 8080:80 --name my-apache-php-app -v "$PWD":/var/www/html php:8.3-apache
```
Then navigate to `http://localhost:8080` in your web browser.

Now you should see the chat application running locally.