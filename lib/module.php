<?php

namespace WS\Migrations;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Exception;
use Bitrix\Main\EventManager;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use WS\Migrations\ChangeDataCollector\Collector;
use WS\Migrations\ChangeDataCollector\CollectorFix;
use WS\Migrations\Entities\AppliedChangesLogModel;
use WS\Migrations\Entities\AppliedChangesLogTable;
use WS\Migrations\Entities\SetupLogModel;
use WS\Migrations\Processes\AddProcess;
use WS\Migrations\Processes\BaseProcess;
use WS\Migrations\Processes\DeleteProcess;
use WS\Migrations\Processes\UpdateProcess;
use WS\Migrations\Reference\ReferenceController;
use WS\Migrations\Reference\ReferenceItem;
use WS\Migrations\SubjectHandlers\BaseSubjectHandler;
use WS\Migrations\SubjectHandlers\IblockHandler;
use WS\Migrations\SubjectHandlers\IblockPropertyHandler;
use WS\Migrations\SubjectHandlers\IblockSectionHandler;
use WS\Migrations\Tests\Starter;

/**
 * Class Module (Singleton)
 *
 * @author Maxim Sokolovsky <sokolovsky@worksolutions.ru>
 */
class Module {

    const FIX_CHANGES_ADD_KEY           = 'add';
    const FIX_CHANGES_BEFORE_CHANGE_KEY = 'beforeChange';
    const FIX_CHANGES_AFTER_CHANGE_KEY  = 'afterChange';
    const FIX_CHANGES_BEFORE_DELETE_KEY = 'beforeDelete';
    const FIX_CHANGES_AFTER_DELETE_KEY  = 'afterDelete';

    const SPECIAL_PROCESS_FIX_REFERENCE = 'reference';
    const SPECIAL_PROCESS_FULL_MIGRATE  = 'fullMigrate';
    const SPECIAL_PROCESS_SCENARIO = 'Scenario';

    const REFERENCE_SUBJECT_ADD    = 'add';
    const REFERENCE_SUBJECT_REMOVE = 'remove';

    private $localizePath = null,
            $fallbackLocale = 'en',
            $localizations = array();

    private static $name = 'ws.migrations';

    private $_handlers = array();

    /**
     * @var Collector
     */
    private $_dutyCollector = null;

    /**
     * @var ReferenceController
     */
    private $_referenceController = null;

    private $_processAdd;
    private $_processUpdate;
    private $_processDelete;

    private $_listenMode = true;

    /**
     * Versions options Cache
     * @var array
     */
    private $_versions;

    /**
     * @var bool
     */
    private $_validVersion = true;

    /**
     * @var SetupLogModel
     */
    private $_setupLog;

    /**
     * @return $this
     */
    private function _enableListen() {
        $this->_listenMode = true;
        return $this;
    }

    /**
     * @return $this
     */
    private function _disableListen() {
        $this->_listenMode = false;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasListen() {
        return (bool)$this->_listenMode;
    }

    private function __construct() {
        $this->localizePath = __DIR__.'/../lang/'.LANGUAGE_ID;

        if (!file_exists($this->localizePath) && $this->fallbackLocale) {
            $this->localizePath = __DIR__.'/../lang/'.$this->fallbackLocale;
        }

        // version`s is checked
        $options = self::getOptions();
        $checkToken = md5($options->version.__FILE__);
        if (!$options->versionCheck) {
            $options->versionCheck = $checkToken;
        }
        $this->_validVersion = $options->versionCheck == $checkToken;
    }

    static public function getName($stripDots = false) {
        $res = static::$name;
        if ($stripDots) {
            $res = str_replace('.', '_', $res);
        }
        return $res;
    }

    /**
     * @return ModuleOptions
     */
    static public function getOptions() {
        return ModuleOptions::getInstance();
    }

    /**
     * @return Module
     */
    static public function getInstance() {
        static $self = null;
        if (!$self) {
            $self = new self;
        }
        return $self;
    }

    static public function listen(){
        EventManager::getInstance()
            ->addEventHandler('main', 'OnBeforeLocalRedirect', array(get_called_class(), 'commitDutyChanges'));
        EventManager::getInstance()
            ->addEventHandler('main', 'OnCheckListGet', array(Starter::className(), 'items'));
        $self = self::getInstance();
        $bxEventManager = EventManager::getInstance();
        foreach ($self->handlers() as $class => $events) {
            foreach ($events as $eventKey => $eventData) {
                $bxEventManager->addEventHandler($eventData[0], $eventData[1], new Callback(function () {
                    $params = func_get_args();
                    $module = array_shift($params);
                    $handlerClass = array_shift($params);
                    $eventKey = array_shift($params);

                    /** @var $module Module */
                    $module->handle($handlerClass, $eventKey, $params);

                }, $self, $class, $eventKey));
            }
        }
        $self->_referenceController = new ReferenceController($self->getDbVersion());
        $fixRefProcess = self::SPECIAL_PROCESS_FIX_REFERENCE;

        $fSetupReferenceFix = function (ReferenceItem $item, $subject) use ($self, $fixRefProcess) {
            $collector = $self->getDutyCollector();
            $fix = $collector->createFix();
            $fix
                ->setName('Reference fix')
                ->setProcess($fixRefProcess)
                ->setSubject($subject)
                ->setUpdateData(array(
                    'reference' => $item->reference,
                    'group' => $item->group,
                    'dbVersion' => $item->dbVersion,
                    'id' => $item->id
                ));
            $collector->registerFix($fix);
        };

        $referenceAddSubject = self::REFERENCE_SUBJECT_ADD;
        $referenceRemoveSubject = self::REFERENCE_SUBJECT_REMOVE;

        $self->_getReferenceController()->onRegister(function (ReferenceItem $item) use ($fSetupReferenceFix, $referenceAddSubject) {
            $fSetupReferenceFix($item, $referenceAddSubject);
        });

        $self->_getReferenceController()->onRemove(function (ReferenceItem $item) use ($fSetupReferenceFix, $referenceRemoveSubject) {
            $fSetupReferenceFix($item, $referenceRemoveSubject);
        });
    }

    /**
     * @param CollectorFix[] $fixes
     * @throws \Exception
     */
    private function _logOwnChanges($fixes) {
        $hasRealChanges = (bool) array_filter($fixes, function ($item) {
            if (!$item instanceof CollectorFix) {
                return false;
            }
            return $item->getProcess() != Module::SPECIAL_PROCESS_FIX_REFERENCE;
        });
        $setupLog = null;
        if ($hasRealChanges) {
            $setupLog = $this->_useSetupLog();
        }
        /** @var CollectorFix $fix */
        foreach ($fixes as $fix) {
            $applyLog = new AppliedChangesLogModel();
            $applyLog->subjectName = $fix->getSubject();
            $applyLog->groupLabel = $fix->getLabel();
            $applyLog->processName = $fix->getProcess();
            $applyLog->description = $fix->getName();
            $applyLog->originalData = $fix->getOriginalData();
            $applyLog->updateData = $fix->getUpdateData();
            $applyLog->source = $this->getDbVersion();
            $applyLog->success = true;
            $applyLog->setSetupLog($setupLog);

            if ($fix->getProcess() == self::SPECIAL_PROCESS_FIX_REFERENCE) {
                $applyLog->description = 'Insert reference';
                $applyLog->subjectName = 'reference';
            }
            if (!$applyLog->save()) {
                throw new \Exception('Not save current changes in log ' . var_export($applyLog->getErrors()));
            }
        }
    }

    static public function commitDutyChanges() {
        $self = self::getInstance();
        if (!$self->_dutyCollector) {
            return null;
        }
        $fixes = $self->getDutyCollector()->getUsesFixed();
        if (!$fixes) {
            return;
        }
        $self->_logOwnChanges($fixes);
        $self->getDutyCollector()->commit($self->getDbVersion(), $self->getVersionOwner());
    }

    /**
     * @param $path
     * @throws \Exception
     * @return Localization
     */
    public function getLocalization($path) {
        if (!isset($this->localizations[$path])) {
            $realPath = $this->localizePath.'/'.str_replace('.', '/',$path).'.php';
            if (!  file_exists($realPath)) {
                throw new \Exception('localization by path not found');
            }
            $this->localizations[$path] = new Localization(include $realPath);
        }
        return $this->localizations[$path];
    }

    /**
     * Meta description uses handlers, been register
     * @return array
     */
    protected function handlers() {
        return array(
            IblockHandler::className() => array(
                self::FIX_CHANGES_ADD_KEY => array('iblock', 'OnAfterIBlockAdd'),
                self::FIX_CHANGES_BEFORE_CHANGE_KEY => array('iblock', 'OnBeforeIBlockUpdate'),
                self::FIX_CHANGES_AFTER_CHANGE_KEY => array('iblock', 'OnAfterIBlockUpdate'),
                self::FIX_CHANGES_BEFORE_DELETE_KEY => array('iblock', 'OnBeforeIBlockDelete'),
                self::FIX_CHANGES_AFTER_DELETE_KEY => array('iblock', 'OnIBlockDelete'),
            ),
            IblockPropertyHandler::className() => array(
                self::FIX_CHANGES_ADD_KEY => array('iblock', 'OnAfterIBlockPropertyAdd'),
                self::FIX_CHANGES_BEFORE_CHANGE_KEY => array('iblock', 'OnBeforeIBlockPropertyUpdate'),
                self::FIX_CHANGES_AFTER_CHANGE_KEY => array('iblock', 'OnAfterIBlockPropertyUpdate'),
                self::FIX_CHANGES_BEFORE_DELETE_KEY => array('iblock', 'OnBeforeIBlockPropertyDelete'),
                self::FIX_CHANGES_AFTER_DELETE_KEY => array('iblock', 'OnIBlockPropertyDelete')
            ),
            IblockSectionHandler::className() => array(
                self::FIX_CHANGES_ADD_KEY => array('iblock', 'OnAfterIBlockSectionAdd'),
                self::FIX_CHANGES_BEFORE_CHANGE_KEY => array('iblock', 'OnBeforeIBlockSectionUpdate'),
                self::FIX_CHANGES_AFTER_CHANGE_KEY => array('iblock', 'OnAfterIBlockSectionUpdate'),
                self::FIX_CHANGES_BEFORE_DELETE_KEY => array('iblock', 'OnBeforeIBlockSectionDelete'),
                self::FIX_CHANGES_AFTER_DELETE_KEY => array('iblock', 'OnAfterIBlockSectionDelete')
            )
        );
    }

    /**
     * @return BaseSubjectHandler[]
     */
    public function getSubjectHandlers() {
        $classes = array_keys($this->handlers());
        $res = array();
        foreach ($classes as $class) {
            $res[] = $this->getSubjectHandler($class);
        }
        return $res;
    }

    /**
     * Returns sign of handlers enable
     * @param string $class Handlers class name instance of \WS\Migrations\SubjectHandlers\BaseSubjectHandler
     * @return bool
     */
    public function isEnableSubjectHandler($class) {
        if (!is_subclass_of($class, BaseSubjectHandler::className())) {
            return false;
        }
        $oneselfEnable = $this->getOptions()->isEnableSubjectHandler($class);
        if (!$oneselfEnable) {
            return false;
        }
        foreach ($class::depends() as $dependClass) {
            if (!$this->isEnableSubjectHandler($dependClass)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Enable subject handler
     * @throws \Exception
     * @param $class
     */
    public function enableSubjectHandler($class) {
        if (!is_subclass_of($class, BaseSubjectHandler::className())) {
            throw new \Exception("class not instance of BaseSubjectHandler");
        }
        $this->getOptions()->enableSubjectHandler($class);
        foreach ($class::depends() as $dependFromClass) {
            $this->enableSubjectHandler($dependFromClass);
        }
    }

    /**
     * Disable subject handler
     * @throws \Exception
     * @param $class
     */
    public function disableSubjectHandler($class) {
        if (!is_subclass_of($class, BaseSubjectHandler::className())) {
            throw new \Exception("class not instance of BaseSubjectHandler");
        }
        $this->getOptions()->disableSubjectHandler($class);
        foreach (array_keys($this->handlers()) as $handlerClass) {
            if (in_array($class, $handlerClass::depends())) {
                $this->disableSubjectHandler($handlerClass);
            }
        }
    }

    /**
     * @param $class
     * @throws \Exception
     * @return BaseSubjectHandler
     */
    public function getSubjectHandler($class) {
        if (! class_exists($class)) {
            foreach (array_keys($this->handlers()) as $handlerClass) {
                $arClassName = explode('\\', $handlerClass);
                if ($class == array_pop($arClassName)) {
                    $class = $handlerClass;
                    break;
                }
            }
        }
        if (!class_exists($class)) {
            throw new \Exception('Class not exists');
        }
        if (!$this->_handlers[$class]) {

            $this->_handlers[$class] = new $class($this->_getReferenceController());
        }
        return $this->_handlers[$class];
    }

    /**
     * @return AddProcess
     */
    private function _getProcessAdd() {
        if (!$this->_processAdd) {
            $this->_processAdd = new AddProcess();
        }
        return $this->_processAdd;
    }

    /**
     * @return UpdateProcess
     */
    private function _getProcessUpdate() {
        if (!$this->_processUpdate) {
            $this->_processUpdate = new UpdateProcess();
        }
        return $this->_processUpdate;
    }

    /**
     * @return DeleteProcess
     */
    private function _getProcessDelete() {
        if (!$this->_processDelete) {
            $this->_processDelete = new DeleteProcess();
        }
        return $this->_processDelete;
    }

    /**
     * @param $class
     * @return BaseProcess
     * @throws \Exception
     */
    public function getProcess($class) {
        switch ($class) {
            case AddProcess::className():
                return $this->_getProcessAdd();
                break;
            case UpdateProcess::className():
                return $this->_getProcessUpdate();
                break;
            case DeleteProcess::className():
                return $this->_getProcessDelete();
                break;
        }
        throw new \Exception("Process class $class not recognized");
    }

    /**
     * @param $handlerClass
     * @param $eventKey
     * @param $params
     * @return bool
     * @throws \Exception
     */
    public function handle($handlerClass, $eventKey, $params) {
        if (!$this->isValidVersion()) {
            throw new \Exception('Module platform version is not valid');
        }
        if (!$this->hasListen()) {
            return false;
        }
        $handlers = $this->handlers();
        if ( !$handlers[$handlerClass][$eventKey]) {
            return false;
        }
        if (!$this->isEnableSubjectHandler($handlerClass)) {
            return false;
        }

        $collector = $this->getDutyCollector();
        $handler = $this->getSubjectHandler($handlerClass);
        $fix  = $collector->createFix();
        $fix->setSubject(get_class($handler));

        $result = false;
        switch ($eventKey) {
            case self::FIX_CHANGES_ADD_KEY:
                $process = $this->_getProcessAdd();
                $fix
                    ->setProcess(get_class($process))
                    ->setName($handler->getName().'. '.$process->getName());
                $result = $process->change($handler, $fix, $params);
                break;
            case self::FIX_CHANGES_BEFORE_CHANGE_KEY:
                $process = $this->_getProcessUpdate();
                $process->beforeChange($handler, $params);
                break;
            case self::FIX_CHANGES_AFTER_CHANGE_KEY:
                $process = $this->_getProcessUpdate();
                $fix
                    ->setProcess(get_class($process))
                    ->setName($handler->getName().'. '.$process->getName());
                $result = $process->afterChange($handler, $fix, $params);
                break;
            case self::FIX_CHANGES_BEFORE_DELETE_KEY:
                $process = $this->_getProcessDelete();
                $process->beforeChange($handler, $params);
                break;
            case self::FIX_CHANGES_AFTER_DELETE_KEY:
                $process = $this->_getProcessDelete();
                $fix
                    ->setProcess(get_class($process))
                    ->setName($handler->getName().'. '.$process->getName());
                $result = $process->afterChange($handler, $fix, $params);
                break;
        }
        $result && $collector->registerFix($fix);
        return true;
    }

    /**
     * @return Collector
     */
    private function _createCollector() {
        return Collector::createInstance($this->_getFixFilesDir(), $this);
    }

    /**
     * @return Collector
     */
    public function getDutyCollector() {
        if (!$this->_dutyCollector) {
            $this->_dutyCollector = $this->_createCollector();
        }
        return $this->_dutyCollector;
    }

    private function _getReferenceController() {
        if (!$this->_referenceController) {
            $this->_referenceController = new ReferenceController($this->getDbVersion());
        }
        return $this->_referenceController;
    }

    /**
     * @param Collector $collector
     * @return $this
     */
    public function injectDutyCollector(Collector $collector) {
        $this->_dutyCollector = $collector;
        return $this;
    }

    /**
     * Directory location fixed files
     * @return string
     */
    private function _getFixFilesDir() {
        return Application::getDocumentRoot().DIRECTORY_SEPARATOR.$this->getOptions()->catalogPath;
    }

    /**
     * Directory location scenarios
     * @return string
     */
    private function _getScenariosDir() {
        return $this->_getFixFilesDir().DIRECTORY_SEPARATOR.'scenarios';

    }

    /**
     * @param $fileName
     * @param $content
     * @return string
     */
    public function putScriptClass($fileName, $content) {
        $file = new File($this->_getScenariosDir().DIRECTORY_SEPARATOR.$fileName);
        $file->putContents($content);
        return $file->getPath();
    }

    /**
     * @return Collector[]
     */
    private function _getNotAppliedCollectors() {
        $files = $this->_getNotAppliedFiles($this->_getFixFilesDir());
        $collectors = array();
        /** @var File $file */
        foreach ($files as $file) {
            $collectors[] = Collector::createByFile($file->getPath(), $this);
        }
        return $collectors;
    }

    /**
     * @return CollectorFix[]
     */
    public function getNotAppliedFixes() {
        $collectors = $this->_getNotAppliedCollectors();
        $result = array();
        foreach ($collectors as $collector) {
            $result = array_merge($result, $collector->getFixes() ?: array());
        }
        return $result;
    }

    /**
     * Gets class not applied scenarios
     * @return array
     */
    public function getNotAppliedScenarios() {
        /** @var File[] $files */
        $files = $this->_getNotAppliedFiles($this->_getFixFilesDir().DIRECTORY_SEPARATOR.'scenarios');
        $res = array();
        foreach ($files as $file) {
            $fileClass = str_replace(".php", "", $file->getName());
            if (!class_exists($fileClass)) {
                include $file->getPath();
            }
            if (!is_subclass_of($fileClass, '\WS\Migrations\ScriptScenario')) {
                continue;
            }
            if (!$fileClass::isValid()) {
                continue;
            }
            $res[] = $fileClass;
        }
        return $res;
    }

    private function _applyFix(CollectorFix $fix, AppliedChangesLogModel $applyFixLog = null) {
        $process = $this->getProcess($fix->getProcess());
        $subjectHandlerClass = $fix->getSubject();
        if (!$this->isEnableSubjectHandler($subjectHandlerClass)) {
            $applyFixLog->success = false;
            $applyFixLog->description = 'Subject handler not active';
            $applyFixLog->save();
            return ;
        }
        $subjectHandler = $this->getSubjectHandler($subjectHandlerClass);
        $result = $process->update($subjectHandler, $fix, $applyFixLog);
        $applyFixLog->success = (bool) $result->isSuccess();
        !$result->isSuccess() && $applyFixLog->description .= '. '.$result->getMessage();
    }

    private function _applyReferenceFix(CollectorFix $fix) {
        $item = new ReferenceItem();
        $data = $fix->getUpdateData();

        $subject = $fix->getSubject() ?: self::REFERENCE_SUBJECT_ADD;

        $item->reference = $data['reference'];
        $item->id = $data['id'];
        $item->group = $data['group'];
        $item->dbVersion = $data['dbVersion'];

        if ($subject == self::REFERENCE_SUBJECT_ADD) {
            try {
                $this->_getReferenceController()->getReferenceValue($item->id, $item->group, $item->dbVersion);
                $this->_getReferenceController()->getItemCurrentVersionByReference($item->reference);
            } catch (\Exception $e) {
                $this->_getReferenceController()->registerItem($item);
            }
        }
        if ($subject == self::REFERENCE_SUBJECT_REMOVE) {
            $this->_getReferenceController()
                ->removeItemById($item->id, $item->group, $item->dbVersion);
        }
    }


    /**
     * @param $fixes
     * @return int
     * @throws \Exception
     */
    public function applyFixesList($fixes) {
        if (!$this->isValidVersion()) {
            throw new \Exception('Module platform version is not valid');
        }
        if (!$fixes) {
            return 0;
        }
        $this->_disableListen();
        $setupLog = $this->_useSetupLog();
        /** @var CollectorFix $fix */
        foreach ($fixes as $fix) {
            $applyFixLog = new AppliedChangesLogModel();
            try {
                $applyFixLog->processName = $fix->getProcess();
                $applyFixLog->subjectName = $fix->getSubject();
                $applyFixLog->setSetupLog($setupLog);
                $applyFixLog->groupLabel = $fix->getLabel();
                $applyFixLog->description = 'References updates';
                $applyFixLog->source = $fix->getDbVersion();

                $this->_useAnotherVersion($fix);
                if ($this->isReferenceFix($fix)) {
                    $applyFixLog->subjectName = 'references';
                    $applyFixLog->success = true;
                    $this->_applyReferenceFix($fix);
                } else {
                    $this->_applyFix($fix, $applyFixLog);
                }
            } catch (\Exception $e) {
                $applyFixLog->success = false;
                $applyFixLog->description = arrayToJson(array(
                    'message' => $fix->getName().': '.$e->getMessage(),
                    'data' => $fix->getUpdateData(),
                    'trace' => $e->getTrace()
                ));
            }
            $applyFixLog->save();
        }
        $this->_enableListen();
        return count($fixes);
    }

    public function isReferenceFix(CollectorFix $fix) {
        return $fix->getProcess() == self::SPECIAL_PROCESS_FIX_REFERENCE;
    }

    /**
     * Apply references by records another versions
     */
    public function applyAnotherReferences() {
        if (!$this->isValidVersion()) {
            throw new \Exception('Module platform version is not valid');
        }
        $fixes = $this->getNotAppliedFixes();
        foreach ($fixes as $fix) {
            if (!$this->isReferenceFix($fix)) {
                continue;
            }
            $this->_applyReferenceFix($fix);
        }
    }

    /**
     * Try is getting a snapshot. This version data can be version for added.
     * @param CollectorFix $fix
     * @return array|mixed
     */
    public function getSnapshotDataByFix(CollectorFix $fix) {
        try {
            $process = $this->getProcess($fix->getProcess());
            if ($process instanceof AddProcess) {
                return array();
            }
            $subjectHandler = $this->getSubjectHandler($fix->getSubject());
            $id = null;
            if ($process instanceof UpdateProcess) {
                $id = $subjectHandler->getIdBySnapshot($fix->getUpdateData());
            }
            if ($process instanceof DeleteProcess) {
                $id = $fix->getUpdateData();
            }
            return $id ? $subjectHandler->getSnapshot($id, $fix->getDbVersion()) : array();
        } catch (\Exception $e) {
            return array();
        }
    }

    /**
     * Applies all fixes
     * @return int
     */
    public function applyNewFixes() {
        $count = $this->applyFixesList($this->getNotAppliedFixes()) ?: 0;
        $count += $this->applyNewScenarios() ?: 0;
        return $count;
    }

    /**
     * @return SetupLogModel
     */
    private function _useSetupLog() {
        if (!$this->_setupLog) {
            $setupLog = new SetupLogModel();
            $setupLog->userId = $this->getCurrentUser()->GetID();
            $setupLog->save();
            $this->_setupLog = $setupLog;
        }
        return $this->_setupLog;
    }

    /**
     * @return \CUser
     */
    public function getCurrentUser() {
        global $USER;
        return $USER ?: new \CUser();
    }

    /**
     * @return SetupLogModel
     */
    public function getLastSetupLog() {
        return SetupLogModel::findOne(array(
            'order' => array('date' => 'desc')
        ));
    }

    /**
     * @return null
     */
    public function rollbackLastChanges() {
        $setupLog = $this->getLastSetupLog();
        if (!$setupLog) {
            return null;
        }
        $this->rollbackByLogs($setupLog->getAppliedLogs() ?: array());
        $setupLog->delete();
    }

    /**
     * @param AppliedChangesLogModel[] $list
     * @return null
     */
    public function rollbackByLogs($list) {
        $this->_disableListen();
        foreach ($list as $log) {
            $log->delete();
            if (!$log->success) {
                continue;
            }
            try {
                $processName = $log->processName;
                if ($processName == self::SPECIAL_PROCESS_SCENARIO) {
                    $class = $log->subjectName;
                    if (!class_exists($class)) {
                        include $this->_getScenariosDir().DIRECTORY_SEPARATOR.$class.'.php';
                    }
                    if (!is_subclass_of($class, '\WS\Migrations\ScriptScenario')) {
                        continue;
                    }
                    $data = $log->updateData;
                    /** @var ScriptScenario $object */
                    $object = new $class($data, $this->_getReferenceController());
                    $object->rollback();
                    continue;
                }
                $process = $this->getProcess($processName);
                $subjectHandler = $this->getSubjectHandler($log->subjectName);
                $process->rollback($subjectHandler, $log);
            } catch (\Exception $e) {
                continue;
            }
        }
        $this->_enableListen();
    }

    /**
     * Value db version
     * @return string
     */
    public function getDbVersion() {
        $options = self::getOptions();
        if (!$options->version) {
            $options->version = md5(time());
            $options->versionCheck = md5($options->version.__FILE__);
            $this->_validVersion = true;
        }
        return $options->version;
    }

    /**
     * Returns valid sign by version
     * @return bool
     */
    public function isValidVersion() {
        return $this->_validVersion;
    }

    /**
     * Return owner db version
     * @return string
     */
    public function getVersionOwner() {
        $options = self::getOptions();
        return $options->owner;
    }

    /**
     * Export data json format
     * @return string
     */
    public function getExportText() {
        $collector = $this->_createCollector();
        // version export
        foreach ($this->_getReferenceController()->getItems() as $item) {
            $fix = $collector->createFix();
            $fix
                ->setName('Reference fix')
                ->setProcess(self::SPECIAL_PROCESS_FIX_REFERENCE)
                ->setUpdateData(array(
                    'reference' => $item->reference,
                    'group' => $item->group,
                    'dbVersion' => $item->dbVersion,
                    'id' => $item->id
                ));
            $collector->registerFix($fix);
        }

        // entities scheme export
        foreach ($this->getSubjectHandlers() as $handler) {
            $ids = $handler->existsIds();
            foreach ($ids as $id) {
                $snapshot = $handler->getSnapshot($id);
                $fix = $collector->createFix();
                $fix->setSubject(get_class($handler))
                    ->setProcess(self::SPECIAL_PROCESS_FULL_MIGRATE)
                    ->setDbVersion($this->getDbVersion())
                    ->setUpdateData($snapshot);
                $collector->registerFix($fix);
            }
        }
        return arrayToJson($collector->getFixesData($this->getDbVersion(), $this->getVersionOwner()));
    }

    /**
     * Refresh current DB version, copy references links
     */
    public function runRefreshVersion() {
        $cloneVersion = md5(time());
        $registerResult = $this->_getReferenceController()->setupNewVersion($cloneVersion);
        if (!$registerResult) {
            return false;
        }
        $moduleOptions = self::getOptions();
        $moduleOptions->version = $cloneVersion;
        $moduleOptions->versionCheck = md5($cloneVersion.__FILE__);
        $this->_validVersion = true;
        $this->_referenceController = null;
        return true;
    }

    public function import($json) {
        $collector = $this->_createCollector(jsonToArray($json));
        $this->applyFixesList($collector->getFixes());
    }

    public function clearReferences() {
        $this->_getReferenceController()->deleteAll();
    }

    public function install() {
        foreach ($this->getSubjectHandlers() as $handler) {
            $handler->registerExistsReferences();
        }
    }

    /**
     * @param CollectorFix $fix
     */
    private function _useAnotherVersion(CollectorFix $fix) {
        $this->_useVersion($fix->getDbVersion(), $fix->getOwner());
    }


    /**
     * @param string $dbVersion
     * @param string $owner
     */
    private function _useVersion($dbVersion, $owner) {
        if (!$owner) {
            return ;
        }
        $this->_versions = $this->_versions ?: $this->getOptions()->getOtherVersions();
        if (!$this->_versions[$dbVersion] || $this->_versions[$dbVersion] != $owner) {
            $this->_versions[$dbVersion] = $owner;
            $this->getOptions()->otherVersions = $this->_versions;
        }
    }

    /**
     * @return array
     */
    public function getAnotherVersions() {
        return array_merge($this->getOptions()->getOtherVersions(), array(
            $this->getDbVersion() => $this->getVersionOwner()
        ));
    }

    /**
     * @param $dir
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\IO\FileNotFoundException
     */
    private function _getNotAppliedFiles($dir) {
        $result = AppliedChangesLogTable::getList(array(
            'select' => array('GROUP_LABEL'),
            'group' => array('GROUP_LABEL')
        ));
        $usesGroups = array_map(function ($row) {
            return $row['GROUP_LABEL'];
        }, $result->fetchAll());
        $dir = new Directory($dir);
        if (!$dir->isExists()) {
            return array();
        }
        $files = array();
        foreach ($dir->getChildren() as $file) {
            if ($file->isDirectory()) {
                continue;
            }
            if (in_array($file->getName(), $usesGroups)) {
                continue;
            }
            $files[$file->getName()] = $file;
        }
        ksort($files);
        return $files;
    }

    public function applyNewScenarios() {
        $classes = $this->getNotAppliedScenarios();
        if (!$classes) {
            return 0;
        }
        $setupLog = $this->_useSetupLog();
        $this->_disableListen();
        foreach ($classes as $class) {
            /** @var ScriptScenario $object */
            $object = new $class(array(), $this->_getReferenceController());
            $applyFixLog = new AppliedChangesLogModel();
            $applyFixLog->processName = self::SPECIAL_PROCESS_SCENARIO;
            $applyFixLog->subjectName = $class;
            $applyFixLog->setSetupLog($setupLog);
            $applyFixLog->groupLabel = $class.'.php';
            $applyFixLog->description = $object->name();
            list($dbVersion, $versionOwner) = $object->version();
            $applyFixLog->source = $dbVersion;
            $this->_useVersion($dbVersion, $versionOwner);
            try {
                $object->commit();
                $applyFixLog->updateData = $object->getData();
                $applyFixLog->success = true;
            } catch (Exception $e) {
                $applyFixLog->success = false;
                $applyFixLog->description .= " Exception:".$e->getMessage();
            }
            $applyFixLog->save();
        }
        $this->_enableListen();
        return count($classes);
    }
}

function jsonToArray($json) {
    global $APPLICATION;
    $value = json_decode($json, true);
    $value = $APPLICATION->ConvertCharsetArray($value, "UTF-8", LANG_CHARSET);
    return $value;
}

function arrayToJson($data) {
    global $APPLICATION;
    $data = $APPLICATION->ConvertCharsetArray($data, LANG_CHARSET, "UTF-8");
    return json_encode($data);
}
