<?php 
	defined('_JEXEC') or die;
	jimport('joomla.plugin.plugin');

	/**
	* 
	*/
	class plgContentMovie extends JPlugin
	{
		/** var array List of fields to look for in the $attribs */
		protected $mvfields;
		/** var string Category */
		protected $category;
		/** var boolean Limit plugin to selected category */
		protected $limit_to_category;

		/** var boolean Include child categories */
		protected $include_child_categories;
		
		public function __construct(& $subject, $config)
		{

			$jinput = JFactory::getApplication()->input;
			$option = $jinput->get('option');
			if ($option <> 'com_content')
			{
				return true;
			}

			parent::__construct($subject, $config);

			$this->category				= $this->params->get('category');
			$this->limit_to_category	= $this->params->get('limit_to_category');
			$this->include_child_categories	= $this->params->get('include_child_categories');
			$this->mvfields					= $this->setMVFields();

			$this->loadLanguage();
		}

		protected function setMVFields()
		{
			$mvfields = array (
				'original',
				'genero',
				'clasif',
				'idioma',
				'formato',
				'duracion',
				'link',
				'video',
				'lunes',
				'martes',
				'miercoles',
				'jueves',
				'viernes',
				'sabado',
				'domingo'
			);
			
			return $mvfields;
		}
		
		public function onContentPrepareForm($form, $data)
		{

			// If the category id is set, then check if the plugin should be limited to a specific category
			if (!empty($data->catid))
			{
				$this->getChildCategories($data->catid);
				if ($this->limit_to_category && !$this->checkCategory($data->catid))
				{
					return true;
				}
			}

			if (!($form instanceof JForm))
			{
				$this->_subject->setError('JERROR_NOT_A_FORM');
				return false;
			}

			// Check that we are manipulating a valid form
			
			$name = $form->getName();
			if (!in_array($name, array('com_content.article')))
			{
				return true;
			}

			// Add the extra fields to the form
			JForm::addFormPath(__DIR__ . '/forms');
			$form->loadFile('content', false);

			// Load the data from table into the form
			$articleId = isset($data->id) ? $data->id : 0;

			// If there is already an $articleId, then the article is in edit mode 
			// and we need to retrieve the data from the database
			if ($articleId)
			{
				// Load the data from the database
				$db = JFactory::getDbo();
				$query = $db->getQuery(true);
				$query->select('article_id, data');
				$query->from('#__content_movie');
				$query->where('article_id = '.$db->Quote($articleId));
				$db->setQuery($query);
			
				$attribs = $db->loadObject();

				// Check for a database error.
				if ($db->getErrorNum())
				{
					$this->_subject->setError($db->getErrorMsg());
					return false;
				}

				// json_decode the data
				if (!empty($attribs->data))
				{
					$mvdata = json_decode(json_decode($attribs->data));
				}
			}

			// fill in the form with data
			if(isset($attribs))
			{
				foreach ($this->mvfields as $mvfield)
				{
					$data->attribs[$mvfield] = isset($mvdata->$mvfield) ? $mvdata->$mvfield : '';
				}
			}
		

			return true;
			
		}

		/**
		 * Runs on content preparation.
		 * Called after the data for a JForm has been retrieved.
		 *
		 * @param	string	$context	The context for the data
		 * @param	object	$data		An object containing the data for the form.
		 *
		 * @return boolean
		 */
		public function onContentPrepareData($context, $data)
		{
			if (is_object($data))
			{
				$articleId = isset($data->id) ? $data->id : 0;
				
				if ($articleId > 0)
				{
					// Load the data from the database
					$db = JFactory::getDbo();
					$query = $db->getQuery(true);
					$query->select('data');
					$query->from('#__content_movie');
					$query->where('article_id = '.$db->Quote($articleId));
					$db->setQuery($query);
					$results = $db->loadObject();

					// Check for a database error
					if ($db->getErrorNum())
					{
						$this->_subject->setError($db->getErrorMsg());
						return false;
					}
					
					$mvdata = (count($results)) ? json_decode(json_decode($results->data)) : new stdClass;

					// Merge the data
					$data->attribs = array();

					foreach ($this->mvfields as $mvfield)
					{
						$data->attribs[$mvfield] = isset($mvdata->$mvfield) ? $mvdata->$mvfield : '';
					}
				}
				else
				{
					// Load the form
					JForm::addFormPath(__DIR__ . '/forms');
					$form = new JForm('com_content.article');
					$form->loadFile('content', false);

					// Merge the default values
					$data->attribs = array();
					foreach ($form->getFieldset('attribs') as $field)
					{
						$data->attribs[] = array($field->fieldname, $field->value);
					}
				}
			}
			
			return true;
		}

		/**
		 * Fires after content save post event hook to save custom data into #__content_mvextras
		 *
		 * @param $context
		 * @param $data
		 * @param $isNew
		 *
		 * @return bool
		 */
		public function onContentAfterSave($context, $data, $isNew)
		{
			// Check if we are manipulating a valid form
			if (!in_array($context, array('com_content.article')))
			{
				return true;
			}
			
			// Get the article id or set to 0 if new article (it should have an id at this point)
			$articleId = isset($data->id) ? $data->id : 0;

			// Get the attributes
			$attribs = json_decode($data->attribs);
			
			// Pull out the extra fields to insert into the table
			$mvattribs = array();
			foreach ($this->mvfields as $mvfield)
			{
				$mvattribs[$mvfield] = isset($attribs->$mvfield) ? $attribs->$mvfield : '';
			}
			$mvattribs = json_encode($mvattribs);

			// Get the database object
			$db = JFactory::getDbo();
			
			// Check for an existing entry
			$db->setQuery('SELECT COUNT(*) FROM #__content_movie WHERE article_id = '.$articleId);
			$res = $db->loadResult();
			
			// Updating or adding
			if (!empty($res)) // updating record
			{
				$this->updateRecord($mvattribs, $articleId);
			}
			else // Adding a new record
			{
				$this->insertRecord($mvattribs, $articleId);
			}
		}

		/**
		* Remove the data when the article is deleted
		*
		* Method is called before (after?) article data is deleted from the database
		*
		* @param string The context for the content passed to the plugin.
		* @param object The data relating to the content that was deleted.
		*
		* @return bool
		* @throws JException
		*/
		public function onContentAfterDelete($context, $data)
		{
			 // get the article id
			$articleId = isset($data->id) ? (int) $data->id : 0;
			
			if ($articleId)
			{
				try
				{
					$db = JFactory::getDbo();
					
					$db->setQuery('DELETE FROM #__content_movie WHERE article_id = '.$articleId );
					
					if (!$db->execute()) {
						throw new Exception($db->getErrorMsg());
					}
				}
				catch (JException $e)
				{
					$this->_subject->setError($e->getMessage());
					
					return false;
				}
			}

			return true;
		}

		/**
		 * The first stage in preparing the content for output
		 *
		 * @param $context
		 * @param $article
		 * @param $params
		 * @param $page
		 *
		 * @return string
		 */
		public function onContentPrepare($context, &$article, &$params, $page = 0)
		{
			if (!isset($article->movie) || !count($article->movie))
			{
				return;
			}


			// construct a result table on the fly   
			jimport('joomla.html.grid');
			$table = new JGrid();

			// Create columns
			$table->addColumn('attr')->addColumn('value');   

			// populate
			$rownr = 0;
			foreach ($article->movie as $attr => $value)
			{
				$table->addRow(array('class' => 'row'.($rownr % 2)));
				$table->setRowCell('attr', $attr);
				$table->setRowCell('value', $value);
				$rownr++;
			}

			// wrap table in a classed <div>
			$suffix = $this->params->get('movieclass_sfx', 'movie');
			$html = '<div class="'.$suffix.'">'.(string)$table.'</div>';

			$article->text = $html.$article->text;
		}

		/**
		* Insert a new record into the database
		*
		* @param $attribs our extra fields in object form
		* @param $articleId the article id we are relating the fields to
		*
		* @return bool
		* @throws Exception
		*/
		public function insertRecord($attribs, $articleId)
		{
			// Get a db connection.
			$db = JFactory::getDbo();

			// Create a new query object.
			$query = $db->getQuery(true);

			// Insert columns.
			$columns = array('article_id', 'data', 'created', 'created_by');

			$user = JFactory::getUser();
			$created_by = $user->id;
			$created = JFactory::getDate()->toSql();

			// Insert values.
			$values = array(
				$articleId,
				$db->quote(json_encode($attribs)),
				$db->quote($created),
				$created_by,
			);

			// insert query
			$query
				->insert($db->quoteName('#__content_movie'))
				->columns($db->quoteName($columns))
				->values(implode(',', $values));

			// set the query
			$db->setQuery($query);

			// execute, throw an exception if we have a problem
			if (!$db->execute()) {
				throw new Exception($db->getErrorMsg());
			}

			return true;
		}

		/**
		* Update record function
		*
		* @param $attribs requires object of attributes from form
		* @param $articleId id of the article we are relating to
		*
		* @return bool
		* @throws Exception
		*/
		protected function updateRecord($attribs, $articleId)
		{
			$db = JFactory::getDbo();
			
			// Create a new query object.
			$query = $db->getQuery(true);
			
			$conditions = array(
				'article_id='.$articleId,
			);

			$user = JFactory::getUser();
			$modified_by = $user->id;
			$modified = JFactory::getDate()->toSql();

			// Fields to update.
			$fields = array(
				'data='.$db->quote(json_encode($attribs)),
				'modified='.$db->quote($modified),
				'modified_by='.$modified_by,
			);

			// update query
			$query->update($db->quoteName('#__content_movie'))->set($fields)->where($conditions);

			// set the query
			$db->setQuery($query);

			// execute, throw an exception if we have a problem
			if (!$db->execute()) {
				throw new Exception($db->getErrorMsg());
			}

			return true;
		}

		protected function checkCategory($article_cat)
		{
			if ($this->category == $article_cat)
			{
				return true;
			}
			
			// Need to check if the current category's parent or grand-parent 
			// is the category selected for this plugin
			if ($this->include_child_categories)
			{
				$parents = $this->getParentCategories($article_cat);
				if (in_array($this->category, $parents))
				{
					return true;
				}
			}
			
			return false;
		}

		protected function getChildCategories($catId)
		{
			jimport('joomla.application.categories');
			$categories = JCategories::getInstance('Content');
			$cat		= $categories->get($catId);
			$children	= $cat->getChildren();
			$childCats	= array();

			foreach ($children as $child)
			{
				$childCats[] = $child->id;
			}

			return $childCats;
		}

		protected function getParentCategories($catId)
		{
			$parentCats	= array();

			jimport('joomla.application.categories');
			$categories = JCategories::getInstance('Content');
			$cat		= $categories->get($catId);

			// Check the parent_id. If it is an integer > 0, update the array and 
			// check for a parent_id of the parent... Only going up 2 levels...
			if ((int)$cat->parent_id)
			{
				$parentCats[]	= $cat->parent_id;
				$parent 		= $cat->getParent();
				if ((int) $parent->parent_id)
				{
					$parentCats[] = $parent->parent_id;
				}
			}

			return $parentCats;
		}
	}
 ?>