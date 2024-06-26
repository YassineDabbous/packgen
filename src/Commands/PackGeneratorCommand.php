<?php

namespace YassineDabbous\Generex\Commands;
use YassineDabbous\Generex\Protocols\CodeGenerator;
use YassineDabbous\Generex\Protocols\DataHolder;
use YassineDabbous\Generex\Protocols\DataGenerator;
use YassineDabbous\Generex\Protocols\TemplateProvider;

/**
 * Class FullPackGenerator.
 *
 * @author  Yassine Dabbous <yassine.dabbous@gmail.com>
 */
class PackGeneratorCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'gen:pack
                            {name : Table name}
                            {--vendor= : Vendor name}
                            {--package= : Package name}
                            {--connection= : Connection name}
                            ';

    /**
     * The console command description.
     */
    protected $description = 'Generate a laravel package with WEB & API CRUD operations';

    public function __construct(
        DataHolder $dataHolder
    ){
        parent::__construct();
        $this->dataHolder = $dataHolder;
    }

    public function handle(CodeGenerator $codeGenerator, DataGenerator $dataGenerator, TemplateProvider $templateProvider) : bool|null
    {
        $this->info('Running Package Generator ...');

        $tableName = $this->getNameInput();
        if(!$dataGenerator->validate($tableName)){
            return false;
        }

        // load "vendor" and "package" names
        $this->buildOptions();

        // generate model fields
        $dataGenerator->generateFields($tableName);

        // generate code
        foreach($templateProvider->stubs() as $stub){
            $codeGenerator->handle($stub);
        }

        if($this->dataHolder->isSingleModule){
            $this->info('In Single Module mode, files such as "ServiceProvider" won\'t be automatically recreated and may need to be updated manually.');
        }

        if($this->ask('Package created successfully. \n Would you like to install it (y/n)?', 'n') != 'y'){
            return true;
        }

        // adding local repository to composer.json
        $this->addPathRepository();

        $this->installPackage();

        return true;
    }
}
