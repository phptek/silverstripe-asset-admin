<?php

namespace SilverStripe\Forms;

use Controller;
use File;
use Folder;
use FormField;
use Member;
use Requirements;
use SS_HTTPRequest;
use SS_HTTPResponse;
use DataList;

/**
 * Class AssetGalleryField
 * @package SilverStripe\Forms
 * @todo Consistent request parameter naming with DataObject properties (capitalisation and naming)
 */
class AssetGalleryField extends FormField {
	/**
	 * @var array
	 */
	private static $allowed_actions = array(
		'fetch',
		'search',
		'update',
		'delete',
	);

	private static $url_handlers = array(
		'GET fetch' => 'fetch',
		'GET search' => 'search',
		'PUT update' => 'update',
		'DELETE delete' => 'delete',
	);

	/**
	 * @config
	 *
	 * @var string
	 */
	private static $defaultPath = 'uploads';

	/**
	 * @var string
	 */
	protected $currentPath;

	/**
	 * @var int
	 */
	protected $limit = 10;

	/**
	 * @var boolean
	 */
	protected $bulkActions = true;

	/**
	 * Data source.
	 *
	 * @var DataList
	 */
	protected $list = null;

	/**
	 * @param string $name
	 * @param string $title
	 * @param DataList $dataList
	 * @throws InvalidArgumentException
	 */
	public function __construct($name, $title = null, DataList $dataList = null) {
		parent::__construct($name, $title, null);

		if($dataList && !is_subclass_of($dataList->dataClass(), 'File')) {
			throw new InvalidArgumentException('AssetGalleryField requires a DataList based on File');
		}

		if(!$dataList) {
			$dataList = File::get();
		}

		$this->setList($dataList);
	}

	/**
	 * Set the data source.
	 *
	 * @param DataList $list
	 *
	 * @return $this
	 */
	public function setList(DataList $list) {
		$this->list = $list;

		return $this;
	}

	/**
	 * Get the data source.
	 *
	 * @return DataList
	 */
	public function getList() {
		return $this->list;
	}

	/**
	 * @return $this
	 */
	public function performReadonlyTransformation() {
		return $this;
	}

	/**
	 * @return string
	 */
	public function Type() {
		return 'asset-gallery';
	}

	/**
	 * Fetches a collection of files by ParentID.
	 *
	 * @param SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse
	 */
	public function fetch(SS_HTTPRequest $request) {
		$params = $request->getVars();
		$items = array();

		if (empty($params['id'])) {
			$this->httpError(400);
		}

		// TODO Limit results to avoid running out of memory (implement client-side pagination)
		$files = $this->getList()->filter('ParentID', $params['id']);

		if ($files) {
			foreach($files as $file) {
				if(!$file->canView()) {
					continue;
				}

				$items[] = $this->getObjectFromData($file);
			}
		}

		$response = new SS_HTTPResponse();
		$response->addHeader('Content-Type', 'application/json');
		$response->setBody(json_encode(array(
			'files' => $items,
			'count' => count($items),
		)));

		return $response;
	}

	/**
	 * @param SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse
	 */
	public function search(SS_HTTPRequest $request) {
		$filters = array();

		if ($name = $request->getVar('name')) {
			$filters['name'] = $name;
		}

		if ($folder = $request->getVar('folder')) {
			$filters['folder'] = $folder;
		}

		if ($folder = $request->getVar('type')) {
			$filters['type'] = $folder;
		}

		if ($createdFrom = $request->getVar('createdFrom')) {
			$filters['createdFrom'] = $createdFrom;
		}

		if ($createdTo = $request->getVar('createdTo')) {
			$filters['createdTo'] = $createdTo;
		}

		if ($onlySearchInFolder = $request->getVar('onlySearchInFolder')) {
			$filters['onlySearchInFolder'] = $onlySearchInFolder;
		}

		$filters['page'] = 1;
		$filters['limit'] = 10;

		if ($page = $request->getVar('page')) {
			$filters['page'] = $page;
		}

		if ($limit = $request->getVar('limit')) {
			$filters['limit'] = $limit;
		}

		$data = $this->getData($filters);

		$response = new SS_HTTPResponse();
		$response->addHeader('Content-Type', 'application/json');
		$response->setBody(json_encode(array(
			'files' => $data['items'],
			'count' => $data['count'],
		)));

		return $response;
	}

	/**
	 * @param SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse
	 */
	public function update(SS_HTTPRequest $request) {
		parse_str($request->getBody(), $vars);
		$id = $vars['id'];
		$file = $this->getList()->filter('id', (int) $id)->first();

		if (!$file) {
			return (new SS_HTTPResponse(json_encode(['status' => 'error']), 500))
				->addHeader('Content-Type', 'application/json');
		}

		if (!$file->canEdit()) {
			return (new SS_HTTPResponse(json_encode(['status' => 'error']), 401))
				->addHeader('Content-Type', 'application/json');
		}

		$title = $request->postVar('title');
		$basename = $request->postVar('basename');

		if (!empty($title)) {
			$file->Title = $title;
		}

		if (!empty($basename)) {
			$file->Name = $basename;
		}

		$file->write();

		return (new SS_HTTPResponse(json_encode(['status' => 'ok']), 200))
			->addHeader('Content-Type', 'application/json');
	}

	/**
	 * @param SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse
	 */
	public function delete(SS_HTTPRequest $request) {
		parse_str($request->getBody(), $vars);
		$fileIds = $vars['ids'];
		$files = array();

		$response = new SS_HTTPResponse();
		$response->addHeader('Content-Type', 'application/json');

		if($fileIds) {
			foreach ($fileIds as $id) {
				if ($file = $this->getList()->filter("id", (int) $id)->first()) {
					array_push($files, $file);
				}
			}
		}

		if (!count($files)) {
			return (new SS_HTTPResponse(json_encode(['status' => 'could not find the file']), 500))
				->addHeader('Content-Type', 'application/json');
		}

		if (!min(array_map(function($file) {return $file->canDelete();}, $files))) {
			return (new SS_HTTPResponse(json_encode(['status' => 'error']), 401))
				->addHeader('Content-Type', 'application/json');
		}

		foreach($files as $file) {
			$file->delete();
		}

		return (new SS_HTTPResponse(json_encode(['status' => 'file was deleted'])))
			->addHeader('Content-Type', 'application/json');
	}

	/**
	 * @param array $filters
	 *
	 * @return array
	 */
	protected function getData($filters = array()) {
		$items = array();
		$files = null;

		$hasFilters = (
			empty($filters["name"])
			&& empty($filters["type"])
			&& empty($filters["createdFrom"])
			&& empty($filters["createdTo"])
		);

		$searchInFolder = (
			!empty($filters['folder'])
			&& isset($filters['onlySearchInFolder'])
			&& $filters['onlySearchInFolder'] == '1'
		);

		if ($searchInFolder || $hasFilters) {
			$folder = null;

			if (isset($filters['folder'])) {
				$folder = $filters['folder'];
			}

			$folder = $this->getFolder($folder);

			if ($folder && $folder->hasChildren()) {
				// When there's a folder with stuff in it.
				/** @var File[]|DataList $files */
				$files = $folder->myChildren();
			} else if ($folder && !$folder->hasChildren()) {
				// When there's an empty folder
				$files = array();
			} else {
				// When there's no folder (we're at the top level).
				$files = $this->getList()->filter('ParentID', 0);
			}
		} else {
			$files = $this->getList();
		}

		$count = 0;

		if($files) {
			if (!empty($filters['name'])) {
				$files = $files->filterAny(array(
					'Name:PartialMatch' => $filters['name'],
					'Title:PartialMatch' => $filters['name']
				));
			}

			if(!empty($filters['createdFrom'])) {
				$fromDate = new DateField(null, null, $filters['createdFrom']);
				$files = $files->filter("Created:GreaterThanOrEqual", $fromDate->dataValue().' 00:00:00');
			}

			if(!empty($filters['createdTo'])) {
				$toDate = new DateField(null, null, $filters['createdTo']);
				$files = $files->filter("Created:LessThanOrEqual", $toDate->dataValue().' 23:59:59');
			}

			if(!empty($filters['type']) && !empty(File::config()->app_categories[$filters['type']])) {
				$extensions = File::config()->app_categories[$filters['type']];
				$files = $files->filter('Name:PartialMatch', $extensions);
			}

			$files = $files->sort(
				'(CASE WHEN "File"."ClassName" = \'Folder\' THEN 0 ELSE 1 END), "Name"'
			);

			$count = $files->count();

			if (isset($filters['page']) && isset($filters['limit'])) {
				$page = $filters['page'];
				$limit = $filters['limit'];

				$offset = ($page - 1) * $limit;

				$files = $files->limit($limit, $offset);
			}

			foreach($files as $file) {
				if(!$file->canView()) {
					continue;
				}

				$items[] = $this->getObjectFromData($file);
			}
		}

		return array(
			"items" => $items,
			"count" => $count,
		);
	}

	/**
	 * @param null|string $folder
	 *
	 * @return null|Folder
	 */
	protected function getFolder($folder = null) {
		if ($folder) {
			return Folder::find_or_make($folder);
		}

		$path = $this->config()->defaultPath;

		if($this->getCurrentPath() !== null) {
			$path = $this->getCurrentPath();
		}

		if (empty($path)) {
			return null;
		}

		return Folder::find_or_make($path);
	}

	/**
	 * @inheritdoc
	 *
	 * @param array $properties
	 *
	 * @return string
	 */
	public function Field($properties = array()) {
		$name = $this->getName();

		Requirements::css(ASSET_ADMIN_DIR . "/javascript/dist/main.css");
		Requirements::javascript(FRAMEWORK_DIR . "/admin/javascript/dist/bundle-react.js");
		Requirements::add_i18n_javascript(ASSET_ADMIN_DIR . "/javascript/lang");
		Requirements::javascript(ASSET_ADMIN_DIR . "/javascript/dist/bundle.js");

		$fetchURL = $this->getFetchURL();
		$searchURL = $this->getSearchURL();
		$updateURL = $this->getUpdateURL();
		$deleteURL = $this->getDeleteURL();
		$initialFolder = $this->getCurrentPath();
		$limit = $this->getLimit();
		$bulkActions = $this->getBulkActions();

		return "<div
			class='asset-gallery-component-wrapper'
			data-asset-gallery-name='{$name}'
			data-asset-gallery-bulk-actions='{$bulkActions}'
			data-asset-gallery-limit='{$limit}'
			data-asset-gallery-fetch-url='{$fetchURL}'
			data-asset-gallery-search-url='{$searchURL}'
			data-asset-gallery-update-url='{$updateURL}'
			data-asset-gallery-delete-url='{$deleteURL}'
			data-asset-gallery-initial-folder='{$initialFolder}'
			></div>";
	}

	/**
	 * @return string
	 */
	protected function getFetchURL() {
		return Controller::join_links($this->Link(), 'fetch');
	}

	/**
	 * @return string
	 */
	protected function getSearchURL() {
		return Controller::join_links($this->Link(), 'search');
	}

	/**
	 * @return string
	 */
	protected function getUpdateURL() {
		return Controller::join_links($this->Link(), 'update');
	}

	/**
	 * @return string
	 */
	protected function getDeleteURL() {
		return Controller::join_links($this->Link(), 'delete');
	}

	/**
	 * @return string
	 */
	public function getCurrentPath() {
		return $this->currentPath;
	}

	/**
	 * @param string $currentPath
	 *
	 * @return $this
	 */
	public function setCurrentPath($currentPath) {
		$this->currentPath = $currentPath;

		return $this;
	}

	/**
	 * @param File $file
	 *
	 * @return array
	 */
	protected function getObjectFromData(File $file) {
		$object = array(
			'id' => $file->ID,
			'created' => $file->Created,
			'lastUpdated' => $file->LastEdited,
			'owner' => null,
			'parent' => null,
			'attributes' => array(
				'dimensions' => array(),
			),
			'title' => $file->Title,
			'type' => $file->is_a('Folder') ? 'folder' : $file->FileType,
			'category' => $file->is_a('Folder') ? 'folder' : $file->appCategory(),
			'basename' => $file->Name,
			'filename' => $file->Filename,
			'extension' => $file->Extension,
			'size' => $file->Size,
			'url' => $file->AbsoluteURL,
			'canEdit' => $file->canEdit(),
			'canDelete' => $file->canDelete()
		);

		/** @var Member $owner */
		$owner = $file->Owner();

		if($owner) {
			$object['owner'] = array(
				'id' => $owner->ID,
				'title' => trim($owner->FirstName . ' ' . $owner->Surname),
			);
		}

		/** @var Folder $parent */
		$parent = $file->Parent();

		if($parent) {
			$object['parent'] = array(
				'id' => $parent->ID,
				'title' => $parent->Title,
				'filename' => $parent->Filename,
			);
		}

		/** @var File $file */
		if($file->hasMethod('getWidth') && $file->hasMethod('getHeight')) {
			$object['attributes']['dimensions']['width'] = $file->Width;
			$object['attributes']['dimensions']['height'] = $file->Height;
		}

		return $object;
	}

	/**
	 * @param int $limit
	 *
	 * @return $this
	 */
	public function setLimit($limit) {
		$this->limit = $limit;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getLimit() {
		return $this->limit;
	}

	/**
	 * @param boolean $bulkActions
	 *
	 * @return $this
	 */
	public function disableBulkActions() {
		$this->bulkActions = false;

		return $this;
	}

	/**
	 * @return boolean
	 */
	public function getBulkActions() {
		return $this->bulkActions;
	}
}
