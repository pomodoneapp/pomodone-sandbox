* Install deps with `composer install` or `./composer.phar install` if you have a local copy
* Configure your web server as per the docs [http://silex.sensiolabs.org/doc/web_servers.html](http://silex.sensiolabs.org/doc/web_servers.html)
* Make sure `resources/log/` folder is writable by the web server
* To create a new provider inherit from the `Pomodone\BaseProvider` class
* Register it with `$app->register(new YourProvider($app['services']['your_provider']));` in App.php
