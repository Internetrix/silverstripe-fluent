<?php

namespace TractorCow\Fluent\Tests\Task;

use Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\State\FluentState;
use TractorCow\Fluent\Task\FluentMigrationTask;
use TractorCow\Fluent\Tests\Task\FluentMigrationTaskTest\TranslatedDataObject;
use TractorCow\Fluent\Tests\Task\FluentMigrationTaskTest\TranslatedDataObjectSubclass;
use TractorCow\Fluent\Tests\Task\FluentMigrationTaskTest\TranslatedDataObjectPartialSubclass;
use TractorCow\Fluent\Tests\Task\FluentMigrationTaskTest\TranslatedPage;

/**
 * @TODO:
 * - test partly translated dataobjects (e.g. only en_US is translated, but not de_AT)
 * - test versions are translated
 *
 * Class FluentMigrationTaskTest
 * @package TractorCow\Fluent\Tests\Task
 */
class FluentMigrationTaskTest extends SapphireTest
{
    protected static $fixture_file = 'FluentMigrationTaskTest.yml';

    protected static $extra_dataobjects = [
        TranslatedDataObject::class,
        TranslatedDataObjectSubclass::class,
        TranslatedDataObjectPartialSubclass::class,
        TranslatedPage::class
    ];

    public function setUp()
    {
        parent::setUp();
        Config::modify()->set('Fluent', 'default_locale', 'en_US');
    }

    /**
     * @useDatabase false
     */
    public function testTestDataObjectsHaveFluentExtensionApplied()
    {
        foreach (self::$extra_dataobjects as $className) {
            $instance = $className::create();
            $hasExtension = $instance->hasExtension(FluentExtension::class);
            $this->assertTrue($hasExtension, $className . ' should have FluentExtension applied');
        }
    }

    public function testFixturesAreSetupWithOldData()
    {
        $house = $this->objFromFixture(TranslatedDataObject::class, 'house');

        $allFields = SQLSelect::create()
            ->setFrom(Config::inst()->get(TranslatedDataObject::class, 'table_name'))
            ->addWhere('ID = ' . $house->ID)
            ->firstRow()
            ->execute();
        $record = $allFields->record();

        $this->assertEquals('A House', $record['Title_en_US']);
        $this->assertEquals('Something', $record['Name_en_US']);
        $this->assertEquals('Ein Haus', $record['Title_de_AT']);
        $this->assertEquals('Irgendwas', $record['Name_de_AT']);

        $tree = $this->objFromFixture(TranslatedDataObjectSubclass::class, 'tree');
        $subclassFields = SQLSelect::create()
            ->setFrom(Config::inst()->get(TranslatedDataObjectSubclass::class, 'table_name'))
            ->addWhere('ID = ' . $tree->ID)
            ->firstRow()
            ->execute();
        $record = $subclassFields->record();
        $this->assertEquals('deciduous trees', $record['Category_en_US']);
        $this->assertEquals('Laubbäume', $record['Category_de_AT']);

        //site tree / versioned objects
        $table = $this->objFromFixture(TranslatedPage::class, 'table');
        $siteTree = SQLSelect::create()
            ->setFrom(Config::inst()->get(SiteTree::class, 'table_name'))
            ->addWhere('ID = ' . $table->ID)
            ->firstRow()
            ->execute();
        $page = SQLSelect::create()
            ->setFrom(Config::inst()->get(TranslatedPage::class, 'table_name'))
            ->addWhere('ID = ' . $table->ID)
            ->firstRow()
            ->execute();

        $siteTreeFields = $siteTree->record();
        $pageFields = $page->record();

        $this->assertEquals('A Table', $siteTreeFields['Title_en_US']);
        $this->assertEquals('Ein Tisch', $siteTreeFields['Title_de_AT']);
        $this->assertEquals('made from wood', $pageFields['TranslatedValue_en_US']);
        $this->assertEquals('aus Holz', $pageFields['TranslatedValue_de_AT']);

        $siteTreeVersion = SQLSelect::create()
            ->setFrom(Config::inst()->get(SiteTree::class, 'table_name') . '_Versions')
            ->addWhere('RecordID = ' . $table->ID . ' AND Version = ' . $table->Version)
            ->firstRow()
            ->execute();

        $pageVersion = SQLSelect::create()
            ->setFrom(Config::inst()->get(TranslatedPage::class, 'table_name') . '_Versions')
            ->addWhere('RecordID = ' . $table->ID . ' AND Version = ' . $table->Version)
            ->firstRow()
            ->execute();

        $siteTreeVersionFields = $siteTreeVersion->record();
        $pageVersionFields = $pageVersion->record();

        $this->assertEquals('A Table', $siteTreeVersionFields['Title_en_US']);
        $this->assertEquals('Ein Tisch', $siteTreeVersionFields['Title_de_AT']);
        $this->assertEquals('made from wood', $pageVersionFields['TranslatedValue_en_US']);
        $this->assertEquals('aus Holz', $pageVersionFields['TranslatedValue_de_AT']);

        $this->assertFalse($table->isPublished(), 'Table should not be published by default');

        $table->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $this->assertTrue($table->isPublished(), 'Table should now be published');

        $siteTreeLiveFields = SQLSelect::create()
            ->setFrom(Config::inst()->get(SiteTree::class, 'table_name') . '_Live')
            ->addWhere('ID = ' . $table->ID)
            ->firstRow()
            ->execute()
            ->record();
        $pageLiveFields = SQLSelect::create()
            ->setFrom(Config::inst()->get(TranslatedPage::class, 'table_name') . '_Live')
            ->addWhere('ID = ' . $table->ID)
            ->firstRow()
            ->execute()
            ->record();

        $this->assertEquals('A Table', $siteTreeLiveFields['Title_en_US']);
        $this->assertEquals('Ein Tisch', $siteTreeLiveFields['Title_de_AT']);
        $this->assertEquals('made from wood', $pageLiveFields['TranslatedValue_en_US']);
        $this->assertEquals('aus Holz', $pageLiveFields['TranslatedValue_de_AT']);
    }

    public function testMigrationTaskMigratesDataObjectsWithoutVersioning()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $house = $this->objFromFixture(TranslatedDataObject::class, 'house');
        $tree = $this->objFromFixture(TranslatedDataObjectSubclass::class, 'tree');

        $this->assertFalse(
            $this->hasLocalisedRecord($house, 'de_AT'),
            'house should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($house, 'en_US'),
            'house should not exist in locale en_US before migration'
        );

        $this->assertFalse(
            $this->hasLocalisedRecord($tree, 'de_AT'),
            'tree should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($tree, 'en_US'),
            'tree should not exist in locale en_US before migration'
        );


        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(TranslatedDataObject::class);

        $task->run($this->getRequest());

        $this->assertTrue(
            $this->hasLocalisedRecord($house, 'de_AT'),
            'house should exist in locale de_AT after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($house, 'en_US'),
            'house should exist in locale en_US after migration'
        );

        //check if all fields have been translated
        $id = $house->ID;
        $houseEN = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('en_US');

            return TranslatedDataObject::get()->byID($id);
        });
        $houseDE = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('de_AT');

            return TranslatedDataObject::get()->byID($id);
        });

        $id = $tree->ID;

        $treeEN = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('en_US');

            return TranslatedDataObject::get()->byID($id);
        });
        $treeDE = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('de_AT');

            return TranslatedDataObject::get()->byID($id);
        });
        $this->assertEquals('Ein Haus', $houseDE->Title, 'German home should have translated Title');
        $this->assertEquals('Irgendwas', $houseDE->Name, 'German home should have translated Name');
        $this->assertEquals('A House', $houseEN->Title, 'English home should have translated Title');
        $this->assertEquals('Something', $houseEN->Name, 'English home should have translated Name');

        $this->assertEquals('Ein Baum', $treeDE->Title, 'German tree should have translated Title');
        $this->assertEquals('Ahorn', $treeDE->Name, 'German tree should have translated Name');
        $this->assertEquals('Laubbäume', $treeDE->Category, 'German tree should have translated Category');
        $this->assertEquals('A Tree', $treeEN->Title, 'English tree should have translated Title');
        $this->assertEquals('Marple', $treeEN->Name, 'English tree should have translated Name');
        $this->assertEquals('deciduous trees', $treeEN->Category, 'English tree should have translated Category');
    }

    public function testMigrationTaskMigratesDataObjectsWithVersioning()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $table = $this->objFromFixture(TranslatedPage::class, 'table');
        $chair = $this->objFromFixture(TranslatedPage::class, 'chair');

        $this->assertFalse(
            $this->hasLocalisedRecord($table, 'de_AT'),
            'table should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($table, 'en_US'),
            'table should not exist in locale en_US before migration'
        );

        $this->assertFalse(
            $this->hasLocalisedRecord($chair, 'de_AT'),
            'chair should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($chair, 'en_US'),
            'chair should not exist in locale en_US before migration'
        );

        $table->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertTrue($table->isPublished(), 'Publishing of Table went wrong');
        $this->assertFalse($chair->isPublished(), 'Chair should be still unpublished');

        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(SiteTree::class);
        $task->run($this->getRequest());

        $this->assertTrue(
            $this->hasLocalisedRecord($table, 'de_AT'),
            'table should exist in locale de_AT after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($table, 'en_US'),
            'table should exist in locale en_US after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($table, 'de_AT', 'Live'),
            'table should exist live in locale de_AT after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($table, 'en_US', 'Live'),
            'table should exist live in locale en_US after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($table, 'de_AT', 'Versions'),
            'table versions should exist in locale de_AT after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($table, 'en_US', 'Versions'),
            'table versions should exist  in locale en_US after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($chair, 'de_AT'),
            'table should exist in locale de_AT after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($chair, 'en_US'),
            'table should exist in locale en_US after migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($chair, 'de_AT', 'Live'),
            'table should not exist live in locale de_AT after migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($chair, 'en_US', 'Live'),
            'table should not exist live in locale en_US after migration'
        );

        $this->assertTrue($table->isPublished(), 'Table should be still published');
        $this->assertFalse($chair->isPublished(), 'Chair should be still unpublished');

        //check if all fields have been translated
        $id = $table->ID;
        $tableEN = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('en_US');

            return TranslatedPage::get()->byID($id);
        });
        $tableDE = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('de_AT');

            return TranslatedPage::get()->byID($id);
        });

        $id = $chair->ID;

        $chairEN = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('en_US');

            return TranslatedPage::get()->byID($id);
        });
        $chairDE = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('de_AT');

            return TranslatedPage::get()->byID($id);
        });
        $this->assertEquals('Ein Tisch', $tableDE->Title, 'German table should have translated Title');
        $this->assertEquals('aus Holz', $tableDE->TranslatedValue, 'German table should have translated Value');
        $this->assertEquals('A Table', $tableEN->Title, 'English table should have translated Title');
        $this->assertEquals('made from wood', $tableEN->TranslatedValue, 'English table should have translated Value');

        $this->assertEquals('Ein Stuhl', $chairDE->Title, 'German chair  should have translated Title');
        $this->assertEquals('aus Kunststoff', $chairDE->TranslatedValue, 'German chair  should have translated Value');
        $this->assertEquals('A Chair', $chairEN->Title, 'English chair  should have translated Title');
        $this->assertEquals('plastic', $chairEN->TranslatedValue, 'English chair  should have translated Value');
    }

    public function testMigrationTaskDoesNotAlterDatabaseWhenWriteFlagUnset()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $house = $this->objFromFixture(TranslatedDataObject::class, 'house');
        $table = $this->objFromFixture(TranslatedPage::class, 'table');

        $this->assertFalse(
            $this->hasLocalisedRecord($house, 'de_AT'),
            'house should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($house, 'en_US'),
            'house should not exist in locale en_US before migration'
        );

        $this->assertFalse(
            $this->hasLocalisedRecord($table, 'de_AT'),
            'table should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($table, 'en_US'),
            'table should not exist in locale en_US before migration'
        );

        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(TranslatedDataObject::class);

        $task->run($this->getRequest(false));

        $this->assertFalse(
            $this->hasLocalisedRecord($house, 'de_AT'),
            'house should not exist in locale de_AT after migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($house, 'en_US'),
            'house should not exist in locale en_US after migration'
        );

        $this->assertFalse(
            $this->hasLocalisedRecord($table, 'de_AT'),
            'table should not exist in locale de_AT after migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($table, 'en_US'),
            'table should not exist in locale en_US after migration'
        );
    }

    public function testMigrationTaskFallsBackToDefaultsForFragmentedTranslations()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $fragmented = $this->objFromFixture(TranslatedDataObject::class, 'fragmented');

        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(TranslatedDataObject::class);

        $task->run($this->getRequest());

        $row = $this->localisedRecord($fragmented, 'de_AT');
        $this->assertEquals('Fernseher', $row['Title']);
        $this->assertEquals('big flatscreen', $row['Name']);

        $row = $this->localisedRecord($fragmented, 'en_US');
        $this->assertEquals('TV', $row['Title']);
        $this->assertEquals('idiot box', $row['Name']);
    }

    public function testMigrationTaskDoesNotCreateRecordIfNoTranslationsExist()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT', 'en_NZ']);

        $partiallyTranslated = $this->objFromFixture(TranslatedDataObject::class, 'partiallyTranslated');

        $this->assertFalse(
            $this->hasLocalisedRecord($partiallyTranslated, 'de_AT'),
            'partiallyTranslated should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($partiallyTranslated, 'en_NZ'),
            'partiallyTranslated should not exist in locale en_NZ before migration'
        );

        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(TranslatedDataObject::class);

        $task->run($this->getRequest());

        $this->assertTrue(
            $this->hasLocalisedRecord($partiallyTranslated, 'de_AT'),
            'partiallyTranslated should exist in locale de_AT after migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($partiallyTranslated, 'en_NZ'),
            'partiallyTranslated should not exist in locale en_NZ after migration'
        );
    }

    public function testMigrationTaskAlwaysCreatesRecordForDefaultLocale()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $unTranslated = $this->objFromFixture(TranslatedDataObject::class, 'unTranslated');

        $this->assertFalse(
            $this->hasLocalisedRecord($unTranslated, 'de_AT'),
            'unTranslated should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($unTranslated, 'en_US'),
            'unTranslated should not exist in locale en_US before migration'
        );

        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(TranslatedDataObject::class);

        $task->run($this->getRequest());

        $this->assertTrue(
            $this->hasLocalisedRecord($unTranslated, 'en_US'),
            'unTranslated should exist in locale en_US after migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($unTranslated, 'de_AT'),
            'unTranslated should not exist in locale de_AT after migration'
        );
    }

    public function testMigrationTaskWritesNullIfSourceFieldDoesNotExist()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $dog = $this->objFromFixture(TranslatedDataObjectPartialSubclass::class, 'dog');

        $this->assertFalse(
            $this->hasLocalisedRecord($dog, 'de_AT'),
            'dog should not exist in locale de_AT before migration'
        );
        $this->assertFalse(
            $this->hasLocalisedRecord($dog, 'en_US'),
            'dog should not exist in locale en_US before migration'
        );

        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(TranslatedDataObject::class);

        $task->run($this->getRequest());

        $this->assertTrue(
            $this->hasLocalisedRecord($dog, 'en_US'),
            'dog should exist in locale en_US after migration'
        );
        $this->assertTrue(
            $this->hasLocalisedRecord($dog, 'de_AT'),
            'dog should exist in locale de_AT after migration'
        );

        $id = $dog->ID;
        $dogDE = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('de_AT');

            return TranslatedDataObject::get()->byID($id);
        });
        $this->assertEquals('Brown', $dogDE->Colour, 'German dog should have default for translated Colour');
        $dogUS = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('en_US');

            return TranslatedDataObject::get()->byID($id);
        });
        $this->assertEquals('Brown', $dogUS->Colour, 'English dog should have default for translated Colour');
    }

    /**
     * Check if a localised record exists for the given locale
     *
     * @param DataObject $record
     * @param string $locale
     * @param string $suffix (e.g. Live, Versions ...)
     * @return boolean
     */
    protected function hasLocalisedRecord(DataObject $record, $locale, $suffix = '')
    {
        $result = $this->localisedRecord($record, $locale, $suffix);
        return !empty($result);
    }

    /**
     * Get the localised record for the given locale if it iexists
     *
     * @param DataObject $record
     * @param string $locale
     * @param string $suffix (e.g. Live, Versions ...)
     * @return boolean
     */
    protected function localisedRecord(DataObject $record, $locale, $suffix = '')
    {
        $table = implode('_', array_filter([
            $record->config()->get('table_name'),
            'Localised',
            $suffix
        ]));

        return SQLSelect::create()
            ->setFrom($table)
            ->setWhere([
                'RecordID' => $record->ID,
                'Locale' => $locale,
            ])
            ->execute()
            ->first();
    }

    public function testMigrationTaskCanRunSafelyASecondTime()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $baseTable = Config::inst()->get(TranslatedDataObject::class, 'table_name');
        $localisedTable = $baseTable . '_Localised';

        //there should be no localised fields when the test starts
        $localisedSelect = SQLSelect::create()
            ->setFrom($localisedTable);

        $this->assertEquals(0, $localisedSelect->count(), 'there should be no localised rows when the test starts');

        $task = FluentMigrationTask::create();
        $task->setMigrateSubclassesOf(TranslatedDataObject::class);
        $task->run($this->getRequest());

        $countAfterMigration = $localisedSelect->count();
        $this->assertGreaterThan(0, $countAfterMigration, 'after task has run there should be localised rows');

        $task->run($this->getRequest());

        $this->assertEquals(
            $countAfterMigration,
            $localisedSelect->count(),
            'after a second run there should be no new localised rows'
        );
    }

    /**
     * @useDatabase false
     */
    public function testMigrationTaskBuildsOnlyQueryForBaseTableForUnverionedObjects()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $task = FluentMigrationTask::create()
            ->setMigrateSubclassesOf(TranslatedDataObject::class);

        $queries = self::callMethod($task, 'buildQueries', []);
        $this->assertArrayHasKey('de_AT', $queries, 'buildQueries should build queries for de_AT');

        $this->assertArrayHasKey(
            'FluentTestDataObject_Localised',
            $queries['de_AT'],
            'buildQueries should have key for base table'
        );
        $this->assertArrayNotHasKey(
            'FluentTestDataObject_Localised_Live',
            $queries['de_AT'],
            'buildQueries should not have key for live table'
        );
        $this->assertArrayNotHasKey(
            'FluentTestDataObject_Localised_Versions',
            $queries['de_AT'],
            'buildQueries should not have key for versions table'
        );

        $this->assertArrayHasKey(
            'FluentTestDataObjectSubclass_Localised',
            $queries['de_AT'],
            'buildQueries should have key for subclass table'
        );
        $this->assertArrayNotHasKey(
            'FluentTestDataObjectSubclass_Localised_Live',
            $queries['de_AT'],
            'buildQueries should not have key for subclass live table'
        );
        $this->assertArrayNotHasKey(
            'FluentTestDataObjectSubclass_Localised_Versions',
            $queries['de_AT'],
            'buildQueries should not have key for subclass versions table'
        );
    }

    /**
     * Helper to test private methods, see https://stackoverflow.com/a/8702347/4137738
     *
     * @param $obj
     * @param $name
     * @param array $args
     * @return mixed
     * @throws \ReflectionException
     */
    public static function callMethod($obj, $name, array $args = [])
    {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }

    /**
     * @useDatabase false
     */
    public function testMigrationTaskBuildsAllQueriesForVersionedDataObjects()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $task = FluentMigrationTask::create()
            ->setMigrateSubclassesOf(SiteTree::class);

        $queries = self::callMethod($task, 'buildQueries', []);

        $this->assertArrayHasKey('de_AT', $queries, 'buildQueries should build queries for de_AT');

        $this->assertArrayHasKey(
            'SiteTree_Localised',
            $queries['de_AT'],
            'buildQueries should have key for base table'
        );
        $this->assertArrayHasKey(
            'SiteTree_Localised_Live',
            $queries['de_AT'],
            'buildQueries should not have key for live table'
        );
        $this->assertArrayHasKey(
            'SiteTree_Localised_Versions',
            $queries['de_AT'],
            'buildQueries should not have key for versions table'
        );
        $this->assertArrayHasKey(
            'FluentTestPage_Localised',
            $queries['de_AT'],
            'buildQueries should have key for base table'
        );
        $this->assertArrayHasKey(
            'FluentTestPage_Localised_Live',
            $queries['de_AT'],
            'buildQueries should not have key for live table'
        );
        $this->assertArrayHasKey(
            'FluentTestPage_Localised_Versions',
            $queries['de_AT'],
            'buildQueries should not have key for versions table'
        );
    }

    public function testQueryBuilderBuildsQueriesForAllNeededTablesOfADataObject()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);

        $task = FluentMigrationTask::create()
            ->setMigrateSubclassesOf(TranslatedPage::class);

        $queries = self::callMethod($task, 'buildQueries', []);

        $this->assertArrayHasKey('de_AT', $queries, 'buildQueries should build queries for de_AT');

        $this->assertArrayHasKey(
            'SiteTree_Localised',
            $queries['de_AT'],
            'buildQueries should have key for base table'
        );
        $this->assertArrayHasKey(
            'SiteTree_Localised_Live',
            $queries['de_AT'],
            'buildQueries should not have key for live table'
        );
        $this->assertArrayHasKey(
            'SiteTree_Localised_Versions',
            $queries['de_AT'],
            'buildQueries should not have key for versions table'
        );
        $this->assertArrayHasKey(
            'FluentTestPage_Localised',
            $queries['de_AT'],
            'buildQueries should have key for base table'
        );
        $this->assertArrayHasKey(
            'FluentTestPage_Localised_Live',
            $queries['de_AT'],
            'buildQueries should not have key for live table'
        );
        $this->assertArrayHasKey(
            'FluentTestPage_Localised_Versions',
            $queries['de_AT'],
            'buildQueries should not have key for versions table'
        );
    }
    /**
     * @useDatabase false
     */
    public function testGetLocales()
    {
        $locales = [
            'de_ch',
            'en_foo'
        ];
        Config::modify()->set('Fluent', 'locales', $locales);

        $task = FluentMigrationTask::create();

        $this->assertEquals($locales, $task->getLocales(), 'getLocales() should get locales from old fluent config');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Fluent.locales is required
     * @useDatabase false
     */
    public function testGetLocalesThrowsExceptionWhenNoConfigIsFound()
    {
        Config::modify()->set('Fluent', 'locales', []);
        $task = FluentMigrationTask::create();
        $task->getLocales();
    }

    /**
     * @useDatabase false
     */
    public function testGetDefaultLocale()
    {
        $task = FluentMigrationTask::create();

        $this->assertEquals('en_US', $task->getDefaultLocale(), 'getDefaultLocale() should get default from old fluent config');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Fluent.default_locale is required
     * @useDatabase false
     */
    public function testGetDefaultLocaleThrowsExceptionWhenNoConfigIsFound()
    {
        Config::modify()->set('Fluent', 'default_locale', null);
        $task = FluentMigrationTask::create();
        $task->getDefaultLocale();
    }

    /**
     * Get a request object to pass to the `run` method
     *
     * @param string $write Value for 'write' getVar
     * @return HTTPRequest
     */
    protected function getRequest($setWrite = true)
    {
        $getVars = [];
        if ($setWrite) {
            $getVars['write'] = 'true';
        }
        return new HTTPRequest('GET', '', $getVars);
    }
}