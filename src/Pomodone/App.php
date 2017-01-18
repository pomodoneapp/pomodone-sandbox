<?php

namespace Pomodone;

use Binfo\Silex\MobileDetectServiceProvider;
use JDesrosiers\Silex\Provider\CorsServiceProvider;
use Kilte\Silex\Pagination\PaginationServiceProvider;
use Monolog\Logger;
//use Pomodone\Providers\Basecamp;
use Pomodone\Integrations\Controller as IntegrationsController;
use Pomodone\Providers\Asana;
use Pomodone\Providers\Basecamp;
use Pomodone\Providers\Basecamp3;
use Pomodone\Providers\BasecampClassic;
use Pomodone\Providers\CustomSource;
use Pomodone\Providers\Dashboard;
use Pomodone\Providers\Evernote;
use Pomodone\Providers\GoogleCalendar;
use Pomodone\Providers\iCalendar;
use Pomodone\Providers\Jira;
use Pomodone\Providers\Local;
use Pomodone\Providers\Pivotal;
use Pomodone\Providers\Todoist;
use Pomodone\Providers\Toodledo;
use Pomodone\Providers\Wunderlist;
use Silex\Application;
use Silex\Provider\HttpCacheServiceProvider;
use Silex\Provider\MonologServiceProvider;
use Mongo\Silex\Provider\MongoServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Silex\Provider\ServiceControllerServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\SwiftmailerServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Silex\Provider\ValidatorServiceProvider;
use Silex\Provider\DoctrineServiceProvider;
use SilexAssetic\AsseticServiceProvider;
use SilexPhpRedis\PhpRedisProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Pomodone\Providers\Trello;
use OAuth;
use Symfony\Component\HttpFoundation\Response;
use Igorw\Silex\ConfigServiceProvider;
use Twig_Environment;
use Twig_SimpleFilter;


class App extends Application {

    use Application\UrlGeneratorTrait;

    public function __construct(array $values = array()) {


        $app = $this;

        parent::__construct($values);


        $this->register(
            new SessionServiceProvider(),
            [
                'session.storage.options' => [
                    'cookie_lifetime' => 3600 * 24 * 365,
                    //'cookie_domain' => 'pomodoneapp.com',
                    'name' => 'POMO_ID',
                ]
            ]
        );

        $user = $app['session']->get('user');


        $env = getenv('APP_ENV') ?: 'prod';
        $app->register(new ConfigServiceProvider(__DIR__ . "/../../resources/configs/real.json"));

        $app->register(new CorsServiceProvider(),
            [
                'cors.allowCredentials' => true
            ]
        );

        $this->register(new ServiceControllerServiceProvider());


        $this->register(
            new MonologServiceProvider(),
            array(
                'monolog.logfile' => __DIR__ . '/../../resources/log/app.log',
                'monolog.name' => 'app',
                'monolog.level' => Logger::WARNING
            )
        );


        $this->register(
            new TwigServiceProvider(),
            array(
                'twig.options' => array(
                    'cache' => false,
                    'strict_variables' => false,
                    'auto_reload' => true
                ),
                'twig.path' => array(__DIR__ . '/../../templates'),

            )
        );




        $app['twig'] = $app->share(
            $app->extend('twig',
                function (Twig_Environment $twig, App $app) {
                    $twig->addFilter(new Twig_SimpleFilter('icon_selector', ['Pomodone\\Utils', 'getPermalinkIcon']));
                    return $twig;
                }
            )
        );




        $app->register(new UrlGeneratorServiceProvider());

        $app->register(new PaginationServiceProvider());



        //Providers
        $app->register(new Wunderlist($app['services']['wunderlist']));

        //Internal providers
        //End Providers

    }


    public function setupRouting()
    {
        $app = $this;

        $app->get('/', function (){
            $items = $this["services.wunderlist.items"]([
                    'service' => 'wunderlist',
                    'linked' => true,
                    'oauth_token' => '',
                    'oauth_token_secret' => '',
                    'datasets' =>
                        [

                        ],

            ]);

            return $this->json($items);
        });

        /*$app
            ->get('/', function (Request $request, Application $app) {
                return 'OK';
            });*/



        $app->error(function (\Exception $e, $code) use ($app) {


            if ($app['debug']) {
                return $e;
            }

            switch ($code) {
                case 404:
                    $page = file_get_contents(__DIR__ . '/../../static/404.html');
                    break;
                default:
                    $page = file_get_contents(__DIR__ . '/../../static/50x.html');
            }

            return new Response($page, $code);

            // ... logic to handle the error and return a Response
        });
    }
}
