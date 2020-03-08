<?php

namespace TractorCow\Fluent\Forms;

use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentVersionedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Provides the "publish this locale" action
 */
class UnpublishAction extends BaseAction
{
    public function getTitle($gridField, $record, $columnName)
    {
        return _t(__CLASS__ . '.UNPUBLISH', 'Unpublish in this locale');
    }

    public function getActions($gridField)
    {
        return ['fluentunpublish'];
    }

    /**
     * Handle an action on the given {@link GridField}.
     *
     * Calls ALL components for every action handled, so the component needs
     * to ensure it only accepts actions it is actually supposed to handle.
     *
     * @param GridField $gridField
     * @param string $actionName Action identifier, see {@link getActions()}.
     * @param array $arguments Arguments relevant for this
     * @param array $data All form data
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== 'fluentunpublish') {
            return;
        }

        // Get parent record and locale
        $rowRecord = DataObject::get($arguments['RecordClass'])->byID($arguments['RecordID']);
        /** @var DataObject $record */
        /** @var Locale $locale */
        list ($record, $locale) = $this->getRecordAndLocale($gridField, $rowRecord);
        if (!$locale || !$record || !$record->isInDB()) {
            return;
        }

        // Unpublish in locale
        FluentState::singleton()->withState(function (FluentState $newState) use ($record, $locale) {
            $newState->setLocale($locale->getLocale());
            /** @var DataObject|Versioned $fresh */
            $fresh = $record->get()->byID($record->ID);
            $fresh->doUnpublish();
        });
    }

    /**
     * Record must be published before it can be unpublished
     *
     * @param DataObject $record
     * @param Locale $locale
     * @return mixed
     */
    protected function appliesToRecord(DataObject $record, Locale $locale)
    {
        /** @var DataObject|FluentVersionedExtension $record */
        return $record
            && $record->hasExtension(FluentVersionedExtension::class)
            && $record->isPublishedInLocale($locale->Locale);
    }

    /**
     *
     * @param GridField $gridField
     * @param DataObject $record
     * @param Locale $locale
     * @param string $columnName
     * @return GridField_FormAction|null
     */
    protected function getButtonAction($gridField, \SilverStripe\ORM\DataObject $record, Locale $locale, $columnName)
    {
        $title = $this->getTitle($gridField, $record, $columnName);
        return GridField_FormAction::create(
            $gridField,
            "FluentUnpublish_{$locale->Locale}_{$record->ID}",
            $title,
            "fluentunpublish",
            [
                'RecordID'    => $record->ID,
                'RecordClass' => get_class($record),
                'Locale'      => $locale->Locale,
            ]
        )
            ->addExtraClass('action--fluentunpublish btn--icon-md font-icon-translatable grid-field__icon-action action-menu--handled')
            ->setAttribute('classNames', 'action--fluentpublish font-icon-translatable')
            ->setDescription($title)
            ->setAttribute('aria-label', $title);
    }
}
