<?php namespace Flarum\Install\Console;

use Flarum\Console\Command;
use Flarum\Core\Model;
use Flarum\Core\Users\User;
use Flarum\Core\Groups\Group;
use Flarum\Core\Groups\Permission;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    /**
     * @var ProvidesData
     */
    protected $dataSource;

    /**
     * @var Container
     */
    protected $container;

    public function __construct(Container $container)
    {
        $this->container = $container;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('install')
            ->setDescription("Run Flarum's installation migration and seeds.")
            ->addOption(
                'defaults',
                'd',
                InputOption::VALUE_NONE,
                'Create default settings and user'
            );
    }

    /**
     * @inheritdoc
     */
    protected function fire()
    {
        $this->init();

        $this->info('Installing Flarum...');

        $this->install();

        $this->info('DONE.');
    }

    protected function init()
    {
        if ($this->input->getOption('defaults')) {
            $this->dataSource = new DefaultData();
        } else {
            $this->dataSource = new DataFromUser($this->input, $this->output, $this->getHelperSet()->get('question'));
        }
    }

    protected function install()
    {
        $this->storeConfiguration();

        $this->runMigrations();

        $this->writeSettings();

        $this->container->register('Flarum\Core\CoreServiceProvider');

        $resolver = $this->container->make('Illuminate\Database\ConnectionResolverInterface');
        Model::setConnectionResolver($resolver);
        Model::setEventDispatcher($this->container->make('events'));

        $this->seedGroups();
        $this->seedPermissions();

        $this->createAdminUser();

    }

    protected function storeConfiguration()
    {
        $dbConfig = $this->dataSource->getDatabaseConfiguration();

        $config = [
            'debug'    => true,
            'database' => [
                'driver'    => $dbConfig['driver'],
                'host'      => $dbConfig['host'],
                'database'  => $dbConfig['database'],
                'username'  => $dbConfig['username'],
                'password'  => $dbConfig['password'],
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => $dbConfig['prefix'],
                'strict'    => false
            ],
        ];

        $this->info('Writing config');

        $this->container->instance('flarum.config', $config);
        file_put_contents(
            base_path('../config.php'),
            '<?php return '.var_export($config, true).';'
        );
    }

    protected function runMigrations()
    {
        $this->container->bind('Illuminate\Database\Schema\Builder', function($container) {
            return $container->make('Illuminate\Database\ConnectionInterface')->getSchemaBuilder();
        });

        $migrator = $this->container->make('Flarum\Migrations\Migrator');
        $migrator->getRepository()->createRepository();

        $migrator->run(base_path('core/migrations'));

        foreach ($migrator->getNotes() as $note) {
            $this->info($note);
        }
    }

    protected function writeSettings()
    {
        $data = $this->dataSource->getSettings();
        $settings = $this->container->make('Flarum\Core\Settings\SettingsRepository');

        $this->info('Writing default settings');

        foreach ($data as $k => $v) {
            $settings->set($k, $v);
        }
    }

    protected function seedGroups()
    {
        Group::unguard();

        $groups = [
            ['Admin', 'Admins', '#B72A2A', 'wrench'],
            ['Guest', 'Guests', null, null],
            ['Member', 'Members', null, null],
            ['Mod', 'Mods', '#80349E', 'bolt']
        ];

        foreach ($groups as $group) {
            Group::create([
                'name_singular' => $group[0],
                'name_plural' => $group[1],
                'color' => $group[2],
                'icon' => $group[3]
            ]);
        }
    }

    protected function seedPermissions()
    {
        $permissions = [
            // Guests can view the forum
            [2, 'forum.view'],

            // Members can create and reply to discussions
            [3, 'forum.startDiscussion'],
            [3, 'discussion.reply'],

            // Moderators can edit + delete stuff
            [4, 'discussion.delete'],
            [4, 'discussion.deletePosts'],
            [4, 'discussion.editPosts'],
            [4, 'discussion.rename'],
        ];

        foreach ($permissions as &$permission) {
            $permission = [
                'group_id'   => $permission[0],
                'permission' => $permission[1]
            ];
        }

        Permission::insert($permissions);
    }

    protected function createAdminUser()
    {
        $admin = $this->dataSource->getAdminUser();
        $db = $this->getDatabaseConnection();

        $this->info('Creating admin user '.$admin['username']);

        User::unguard();

        $user = new User($admin);
        $user->is_activated = 1;
        $user->join_time = time();
        $user->save();

        $user->groups()->sync([1]);
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    protected function getDatabaseConnection()
    {
        return $this->container->make('Illuminate\Database\ConnectionInterface');
    }
}