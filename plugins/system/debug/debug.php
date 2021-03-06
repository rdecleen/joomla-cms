<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Debug
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die;

/**
 * Joomla! Debug plugin
 *
 * @package     Joomla.Plugin
 * @subpackage  System.Debug
 * @since       1.5
 */
class PlgSystemDebug extends JPlugin
{
	protected $linkFormat = '';

	/**
	 * True if debug lang is on.
	 *
	 * @var    boolean
	 * @since  3.0
	 */
	private $debugLang = false;

	/**
	 * Holds log entries handled by the plugin.
	 *
	 * @var    array
	 * @since  3.1
	 */
	private $logEntries = array();

	/**
	 * Holds SHOW PROFILES of queries
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $sqlShowProfiles = array();

	/**
	 * Holds all SHOW PROFILE FOR QUERY n, indexed by n-1
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $sqlShowProfileEach = array();

	/**
	 * Holds all EXPLAIN EXTENDED for all queries
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $explains = array();

	/**
	 * Constructor.
	 *
	 * @param   object  &$subject  The object to observe
	 * @param   array   $config    An array that holds the plugin configuration
	 *
	 * @since   1.5
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		// Log the deprecated API.
		if ($this->params->get('log-deprecated'))
		{
			JLog::addLogger(array('text_file' => 'deprecated.php'), JLog::ALL, array('deprecated'));
		}

		$this->debugLang = JFactory::getApplication()->getCfg('debug_lang');

		// Only if debugging or language debug is enabled
		if (JDEBUG || $this->debugLang)
		{
			JFactory::getConfig()->set('gzip', 0);
			ob_start();
			ob_implicit_flush(false);
		}

		$this->linkFormat = ini_get('xdebug.file_link_format');

		if ($this->params->get('logs', 1))
		{
			$priority = 0;

			foreach ($this->params->get('log_priorities', array()) as $p)
			{
				$const = 'JLog::' . strtoupper($p);

				if (!defined($const))
				{
					continue;
				}

				$priority |= constant($const);
			}

			// Split into an array at any character other than alphabet, numbers, _, ., or -
			$categories = array_filter(preg_split('/[^A-Z0-9_\.-]/i', $this->params->get('log_categories', '')));
			$mode = $this->params->get('log_category_mode', 0);

			JLog::addLogger(array('logger' => 'callback', 'callback' => array($this, 'logger')), $priority, $categories, $mode);
		}

		// Prepare disconnect-handler for SQL profiling:
		$db = JFactory::getDbo();
		$db->addDisconnectHandler(array($this, 'mysqlDisconnectHandler'));
	}

	/**
	 * Add the CSS for debug. We can't do this in the constructor because
	 * stuff breaks.
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function onAfterDispatch()
	{
		// Only if debugging or language debug is enabled
		if ((JDEBUG || $this->debugLang) && $this->isAuthorisedDisplayDebug())
		{
			JHtml::_('stylesheet', 'cms/debug.css', array(), true);
		}

		// Only if debugging is enabled for SQL queries popovers
		if (JDEBUG && $this->isAuthorisedDisplayDebug())
		{
			JHtml::_('bootstrap.tooltip');
			JHtml::_('bootstrap.popover', '.hasPopover', array('placement' => 'top'));
		}
	}

	/**
	 * Show the debug info
	 *
	 * @since  1.6
	 */
	public function __destruct()
	{
		// Do not render if debugging or language debug is not enabled
		if (!JDEBUG && !$this->debugLang)
		{
			return;
		}

		// User has to be authorised to see the debug information
		if (!$this->isAuthorisedDisplayDebug())
		{
			return;
		}

		// Only render for HTML output
		if (JFactory::getDocument()->getType() !== 'html')
		{
			return;
		}

		// Capture output
		$contents = ob_get_contents();

		if ($contents)
		{
			ob_end_clean();
		}

		// No debug for Safari and Chrome redirection
		if (strstr(strtolower($_SERVER['HTTP_USER_AGENT']), 'webkit') !== false
			&& substr($contents, 0, 50) == '<html><head><meta http-equiv="refresh" content="0;'
		)
		{
			echo $contents;
			return;
		}

		// Load language
		$this->loadLanguage();

		$html = array();

		// Some "mousewheel protecting" JS
		$html[] = "<script>function toggleContainer(name)
		{
			var e = document.getElementById(name);// MooTools might not be available ;)
			e.style.display = (e.style.display == 'none') ? 'block' : 'none';
		}</script>";

		$html[] = '<div id="system-debug" class="profiler">';

		$html[] = '<h1>' . JText::_('PLG_DEBUG_TITLE') . '</h1>';

		if (JDEBUG)
		{
			if (JError::getErrors())
			{
				$html[] = $this->display('errors');
			}

			$html[] = $this->display('session');

			if ($this->params->get('profile', 1))
			{
				$html[] = $this->display('profile_information');
			}

			if ($this->params->get('memory', 1))
			{
				$html[] = $this->display('memory_usage');
			}

			if ($this->params->get('queries', 1))
			{
				$html[] = $this->display('queries');
			}

			if ($this->params->get('logs', 1) && !empty($this->logEntries))
			{
				$html[] = $this->display('logs');
			}
		}

		if ($this->debugLang)
		{
			if ($this->params->get('language_errorfiles', 1))
			{
				$languageErrors = JFactory::getLanguage()->getErrorFiles();
				$html[] = $this->display('language_files_in_error', $languageErrors);
			}

			if ($this->params->get('language_files', 1))
			{
				$html[] = $this->display('language_files_loaded');
			}

			if ($this->params->get('language_strings'))
			{
				$html[] = $this->display('untranslated_strings');
			}
		}

		$html[] = '</div>';

		echo str_replace('</body>', implode('', $html) . '</body>', $contents);
	}

	/**
	 * Method to check if the current user is allowed to see the debug information or not.
	 *
	 * @return  boolean  True is access is allowed
	 *
	 * @since   3.0
	 */
	private function isAuthorisedDisplayDebug()
	{
		static $result = null;

		if (!is_null($result))
		{
			return $result;
		}

		// If the user is not allowed to view the output then end here
		$filterGroups = (array) $this->params->get('filter_groups', null);

		if (!empty($filterGroups))
		{
			$userGroups = JFactory::getUser()->get('groups');

			if (!array_intersect($filterGroups, $userGroups))
			{
				$result = false;

				return false;
			}
		}

		$result = true;

		return true;
	}

	/**
	 * General display method.
	 *
	 * @param   string  $item    The item to display
	 * @param   array   $errors  Errors occured during execution
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function display($item, array $errors = array())
	{
		$title = JText::_('PLG_DEBUG_' . strtoupper($item));

		$status = '';

		if (count($errors))
		{
			$status = ' dbg-error';
		}

		$fncName = 'display' . ucfirst(str_replace('_', '', $item));

		if (!method_exists($this, $fncName))
		{
			return __METHOD__ . ' -- Unknown method: ' . $fncName . '<br />';
		}

		$html = '';

		$js = "toggleContainer('dbg_container_" . $item . "');";

		$class = 'dbg-header' . $status;

		$html[] = '<div class="' . $class . '" onclick="' . $js . '"><a href="javascript:void(0);"><h3>' . $title . '</h3></a></div>';

		// @todo set with js.. ?
		$style = ' style="display: none;"';

		$html[] = '<div ' . $style . ' class="dbg-container" id="dbg_container_' . $item . '">';
		$html[] = $this->$fncName();
		$html[] = '</div>';

		return implode('', $html);
	}

	/**
	 * Display session information.
	 *
	 * Called recursive.
	 *
	 * @param   string   $key      A session key
	 * @param   mixed    $session  The session array, initially null
	 * @param   integer  $id       The id is used for JS toggling the div
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displaySession($key = '', $session = null, $id = 0)
	{
		if (!$session)
		{
			$session = $_SESSION;
		}

		$html = array();
		static $id;

		if (!is_array($session))
		{
			$html[] = $key . ' &rArr;' . $session . PHP_EOL;
		}
		else
		{
			foreach ($session as $sKey => $entries)
			{
				$display = true;

				if (is_array($entries) && $entries)
				{
					$display = false;
				}

				if (is_object($entries))
				{
					$o = JArrayHelper::fromObject($entries);

					if ($o)
					{
						$entries = $o;
						$display = false;
					}
				}

				if (!$display)
				{
					$js = "toggleContainer('dbg_container_session" . $id . '_' . $sKey . "');";

					$html[] = '<div class="dbg-header" onclick="' . $js . '"><a href="javascript:void(0);"><h3>' . $sKey . '</h3></a></div>';

					// @todo set with js.. ?
					$style = ' style="display: none;"';

					$html[] = '<div ' . $style . ' class="dbg-container" id="dbg_container_session' . $id . '_' . $sKey . '">';
					$id++;

					// Recurse...
					$this->displaySession($sKey, $entries, $id);

					$html[] = '</div>';

					continue;
				}

				if (is_array($entries))
				{
					$entries = implode($entries);
				}

				if (is_string($entries))
				{
					$html[] = '<code>';
					$html[] = $sKey . ' &rArr; ' . $entries . '<br />';
					$html[] = '</code>';
				}
			}
		}

		return implode('', $html);
	}

	/**
	 * Display errors.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayErrors()
	{
		$html = array();

		$html[] = '<ol>';

		while ($error = JError::getError(true))
		{
			$col = (E_WARNING == $error->get('level')) ? 'red' : 'orange';

			$html[] = '<li>';
			$html[] = '<b style="color: ' . $col . '">' . $error->getMessage() . '</b><br />';

			$info = $error->get('info');

			if ($info)
			{
				$html[] = '<pre>' . print_r($info, true) . '</pre><br />';
			}

			$html[] = $this->renderBacktrace($error);
			$html[] = '</li>';
		}

		$html[] = '</ol>';

		return implode('', $html);
	}

	/**
	 * Display profile information.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayProfileInformation()
	{
		$html = array();

		$htmlMarks = array();

		$totalTime = 0;
		$totalMem = 0;
		$marks = array();

		foreach (JProfiler::getInstance('Application')->getMarks() as $mark)
		{
			$totalTime += $mark->time;
			$totalMem += $mark->memory;
			$htmlMark = sprintf(
				JText::_('PLG_DEBUG_TIME') . ': <span class="label label-time">%.1f&nbsp;ms</span> / <span class="label">%.1f&nbsp;ms</span>'
				. ' ' . JText::_('PLG_DEBUG_MEMORY') . ': <span class="label label-memory">%0.3f MB</span> / <span class="label">%0.2f MB</span>'
				. ' %s: %s',
				$mark->time,
				$mark->totalTime,
				$mark->memory,
				$mark->totalMemory,
				$mark->prefix,
				$mark->label
			);

			$marks[] = (object) array(
				'time' => $mark->time,
				'memory' => $mark->memory,
				'html' => $htmlMark,
				'tip' => $mark->label
			);
		}

		$avgTime = $totalTime / count($marks);
		$avgMem = $totalMem / count($marks);

		foreach ($marks as $mark)
		{
			if ($mark->time > $avgTime * 1.5)
			{
				$barClass = 'bar-danger';
				$labelClass = 'label-important';
			}
			elseif ($mark->time < $avgTime / 1.5)
			{
				$barClass = 'bar-success';
				$labelClass = 'label-success';
			}
			else
			{
				$barClass = 'bar-warning';
				$labelClass = 'label-warning';
			}

			if ($mark->memory > $avgMem * 1.5)
			{
				$barClassMem = 'bar-danger';
				$labelClassMem = 'label-important';
			}
			elseif ($mark->memory < $avgMem / 1.5)
			{
				$barClassMem = 'bar-success';
				$labelClassMem = 'label-success';
			}
			else
			{
				$barClassMem = 'bar-warning';
				$labelClassMem = 'label-warning';
			}

			$bars[] = (object) array(
				'width' => round($mark->time / ($totalTime / 100), 4),
				'class' => $barClass,
				'tip' => $mark->tip
			);

			$barsMem[] = (object) array(
				'width' => round($mark->memory / ($totalMem / 100), 4),
				'class' => $barClassMem,
				'tip' => $mark->tip
			);

			$htmlMarks[] = '<div>' . str_replace('label-time', $labelClass, str_replace('label-memory', $labelClassMem, $mark->html)) . '</div>';
		}

		$html[] = '<h4>' . JText::_('PLG_DEBUG_TIME') . '</h4>';
		$html[] = $this->renderBars($bars, 'profile');
		$html[] = '<h4>' . JText::_('PLG_DEBUG_MEMORY') . '</h4>';
		$html[] = $this->renderBars($barsMem, 'profile');

		$html[] = '<div class="dbg-profile-list">' . implode('', $htmlMarks) . '</div>';

		$db = JFactory::getDbo();

		$log = $db->getLog();

		if ($log)
		{
			$timings = $db->getTimings();

			if ($timings)
			{
				$totalQueryTime = 0.0;
				$lastStart = null;

				foreach ($timings as $k => $v)
				{
					if (!($k % 2))
					{
						$lastStart = $v;
					}
					else
					{
						$totalQueryTime += $v - $lastStart;
					}
				}

				$totalQueryTime = $totalQueryTime * 1000;

				if ($totalQueryTime > ($totalTime * 0.25))
				{
					$labelClass = 'label-important';
				}
				elseif ($totalQueryTime < ($totalTime * 0.15))
				{
					$labelClass = 'label-success';
				}
				else
				{
					$labelClass = 'label-warning';
				}

				$html[] = '<br /><div>' . JText::sprintf(
						'PLG_DEBUG_QUERIES_TIME',
						sprintf('<span class="label ' . $labelClass . '">%.1f&nbsp;ms</span>', $totalQueryTime)
					) . '</div>';
			}
		}

		return implode('', $html);
	}

	/**
	 * Display memory usage
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayMemoryUsage()
	{
		$bytes = memory_get_usage();

		return '<span class="label">' . JHtml::_('number.bytes', $bytes) . '</span>'
			. ' (<span class="label">' . number_format($bytes) . ' ' . JText::_('PLG_DEBUG_BYTES') . '</span>)';
	}

	/**
	 * Display logged queries.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayQueries()
	{
		$db = JFactory::getDbo();

		$log = $db->getLog();

		if (!$log)
		{
			return null;
		}

		$timings = $db->getTimings();
		$callStacks = $db->getCallStacks();

		$db->setDebug(false);

		$selectQueryTypeTicker = array();
		$otherQueryTypeTicker = array();

		$timing = array();
		$maxtime = 0;

		if (isset($timings[0]))
		{
			$startTime = $timings[0];
			$endTime = $timings[count($timings) - 1];
			$totalBargraphTime = $endTime - $startTime;

			if ($totalBargraphTime > 0)
			{
				foreach ($log as $id => $query)
				{
					if (isset($timings[$id * 2 + 1]))
					{
						// Compute the query time: $timing[$k] = array( queryTime, timeBetweenQueries ):
						$timing[$id] = array(($timings[$id * 2 + 1] - $timings[$id * 2]) * 1000, $id > 0 ? ($timings[$id * 2] - $timings[$id * 2 - 1]) * 1000 : 0);
						$maxtime = max($maxtime, $timing[$id]['0']);
					}
				}
			}
		}
		else
		{
			$startTime = null;
			$totalBargraphTime = 1;
		}

		$bars = array();
		$info = array();
		$totalQueryTime = 0;
		$duplicates = array();

		foreach ($log as $id => $query)
		{
			$did = md5($query);

			if (!isset($duplicates[$did]))
			{
				$duplicates[$did] = array();
			}

			$duplicates[$did][] = $id;

			if ($timings && isset($timings[$id * 2 + 1]))
			{
				// Compute the query time:
				$queryTime = ($timings[$id * 2 + 1] - $timings[$id * 2]) * 1000;
				$totalQueryTime += $queryTime;

				// Run an EXPLAIN EXTENDED query on the SQL query if possible:
				$hasWarnings = false;
				$hasWarningsInProfile = false;

				if (isset($this->explains[$id]))
				{
					$explain = $this->tableToHtml($this->explains[$id], $hasWarnings);
				}
				else
				{
					$explain = JText::sprintf('PLG_DEBUG_QUERY_EXPLAIN_NOT_POSSIBLE', htmlspecialchars($query));
				}

				// Run a SHOW PROFILE query:
				$profile = '';

				if (in_array($db->name, array('mysqli', 'mysql')))
				{
					if (isset($this->sqlShowProfileEach[$id]))
					{
						$profileTable = $this->sqlShowProfileEach[$id];
						$profile = $this->tableToHtml($profileTable, $hasWarningsInProfile);
					}
				}

				$ratio = 0.5; // how heavy should the string length count: 0 - 1
				$timeScore = $queryTime / (strlen($query) * $ratio) * 200;

				// Determine color of bargraph depending on query speed and presence of warnings in EXPLAIN:
				if ($timeScore > 10)
				{
					$barClass = 'bar-danger';
					$labelClass = 'label-important';
				}
				elseif ($hasWarnings || $timeScore > 5)
				{
					$barClass = 'bar-warning';
					$labelClass = 'label-warning';
				}
				else
				{
					$barClass = 'bar-success';
					$labelClass = 'label-success';
				}

				// Computes bargraph as follows: Position begin and end of the bar relatively to whole execution time:
				$prevBar = ($id && isset($bars[$id - 1])) ? $bars[$id - 1] : 0;

				$barPre = round($timing[$id][1] / ($totalBargraphTime * 10), 4);
				$barWidth = round($timing[$id][0] / ($totalBargraphTime * 10), 4);
				$minWidth = 0.3;

				if ($barWidth < $minWidth)
				{
					$barPre -= ($minWidth - $barWidth);

					if ($barPre < 0)
					{
						$minWidth += $barPre;
						$barPre = 0;
					}

					$barWidth = $minWidth;
				}

				$bars[$id] = (object) array(
					'class' => $barClass,
					'width' => $barWidth,
					'pre' => $barPre,
					'tip' => sprintf('%.2f&nbsp;ms', $queryTime)
				);
				$info[$id] = (object) array(
					'class' => $labelClass,
					'explain' => $explain,
					'profile' => $profile,
					'hasWarnings' => $hasWarnings
				);
			}
		}

		// Remove single queries from $duplicates
		$total_duplicates = 0;

		foreach ($duplicates as $did => $dups)
		{
			if (count($dups) < 2)
			{
				unset($duplicates[$did]);
			}
			else
			{
				$total_duplicates += count($dups);
			}
		}

		// Fix first bar width
		$minWidth = 0.3;

		if ($bars[0]->width < $minWidth && isset($bars[1]))
		{
			$bars[1]->pre -= ($minWidth - $bars[0]->width);

			if ($bars[1]->pre < 0)
			{
				$minWidth += $bars[1]->pre;
				$bars[1]->pre = 0;
			}

			$bars[0]->width = $minWidth;
		}

		$memoryUsageNow = memory_get_usage();
		$list = array();

		foreach ($log as $id => $query)
		{
			// Start Query Type Ticker Additions
			$fromStart = stripos($query, 'from');
			$whereStart = stripos($query, 'where', $fromStart);

			if ($whereStart === false)
			{
				$whereStart = stripos($query, 'order by', $fromStart);
			}

			if ($whereStart === false)
			{
				$whereStart = strlen($query) - 1;
			}

			$fromString = substr($query, 0, $whereStart);
			$fromString = str_replace("\t", " ", $fromString);
			$fromString = str_replace("\n", " ", $fromString);
			$fromString = trim($fromString);

			// Initialize the select/other query type counts the first time:
			if (!isset($selectQueryTypeTicker[$fromString]))
			{
				$selectQueryTypeTicker[$fromString] = 0;
			}

			if (!isset($otherQueryTypeTicker[$fromString]))
			{
				$otherQueryTypeTicker[$fromString] = 0;
			}

			// Increment the count:
			if (stripos($query, 'select') === 0)
			{
				$selectQueryTypeTicker[$fromString] = $selectQueryTypeTicker[$fromString] + 1;
				unset($otherQueryTypeTicker[$fromString]);
			}
			else
			{
				$otherQueryTypeTicker[$fromString] = $otherQueryTypeTicker[$fromString] + 1;
				unset($selectQueryTypeTicker[$fromString]);
			}

			$text = $this->highlightQuery($query);

			if ($timings && isset($timings[$id * 2 + 1]))
			{
				// Compute the query time:
				$queryTime = ($timings[$id * 2 + 1] - $timings[$id * 2]) * 1000;

				// Timing
				// Formats the output for the query time with EXPLAIN query results as tooltip:
				$htmlTiming = '<div style="margin: 0px 0 5px;"><span class="dbg-query-time">' . JText::sprintf('PLG_DEBUG_QUERY_TIME', sprintf('<span class="label ' . $info[$id]->class . '">%.2f&nbsp;ms</span>', $timing[$id]['0']));

				if ($timing[$id]['1'])
				{
					$htmlTiming .= ' ' . JText::sprintf('PLG_DEBUG_QUERY_AFTER_LAST', sprintf('<span class="label">%.2f&nbsp;ms</span>', $timing[$id]['1']));
				}

				if (isset($callStacks[$id][0]['memory']))
				{
					$memoryUsed = $callStacks[$id][0]['memory'][1] - $callStacks[$id][0]['memory'][0];
					$memoryBeforeQuery = $callStacks[$id][0]['memory'][0];

					// Determine color of query memory usage:
					if ($memoryUsed > 0.1 * $memoryUsageNow)
					{
						$labelClass = 'label-important';
					}
					elseif ($memoryUsed > 0.05 * $memoryUsageNow)
					{
						$labelClass = 'label-warning';
					}
					else
					{
						$labelClass = 'label-success';
					}

					$htmlTiming .= ' ' . '<span class="dbg-query-memory">' . JText::sprintf('PLG_DEBUG_MEMORY_USED_FOR_QUERY',
							sprintf('<span class="label ' . $labelClass . '">%.3f&nbsp;MB</span>', $memoryUsed / 1048576),
							sprintf('<span class="label">%.3f&nbsp;MB</span>', $memoryBeforeQuery / 1048576)
						)
						. '</span>';

					if ($callStacks[$id][0]['memory'][2] !== null)
					{
						// Determine color of number or results:
						$resultsReturned = $callStacks[$id][0]['memory'][2];

						if ($resultsReturned > 3000)
						{
							$labelClass = 'label-important';
						}
						elseif ($resultsReturned > 1000)
						{
							$labelClass = 'label-warning';
						}
						elseif ($resultsReturned == 0)
						{
							$labelClass = '';
						}
						else
						{
							$labelClass = 'label-success';
						}

						$htmlResultsReturned = '<span class="label ' . $labelClass . '">' . (int) $resultsReturned . '</span>';
						$htmlTiming .= ' ' . '<span class="dbg-query-rowsnumber">' . JText::sprintf('PLG_DEBUG_ROWS_RETURNED_BY_QUERY', $htmlResultsReturned) . '</span>';
					}
				}

				$htmlTiming .= '</div>';

				// Bar
				$htmlBar = $this->renderBars($bars, 'query', $id);

				// Profile Query
				$title = JText::_('PLG_DEBUG_PROFILE');

				if (!$info[$id]->profile)
				{
					$title = '<span class="dbg-noprofile">' . $title . '</span>';
				}

				$htmlProfile = ($info[$id]->profile ? $info[$id]->profile : JText::_('PLG_DEBUG_NO_PROFILE'));

				// Backtrace/Called
				$htmlCallStack = '';

				if (isset($callStacks[$id]))
				{
					$htmlCallStackElements = array();

					foreach ($callStacks[$id] as $functionCall)
					{
						if (isset($functionCall['file']) && isset($functionCall['line']) && (strpos($functionCall['file'], '/libraries/joomla/database/') === false))
						{
							$htmlFile = htmlspecialchars($functionCall['file']);
							$htmlLine = htmlspecialchars($functionCall['line']);
							$htmlCallStackElements[] = '<span class="dbg-log-called-from">' . $this->formatLink($htmlFile, $htmlLine) . '</span>';
						}
					}

					$htmlCallStack = '<div class="dbg-query-table"><div>' . implode('</div><div>', $htmlCallStackElements) . '</div></div>';

					if (!$this->linkFormat)
					{
						$htmlCallStack .= '<div>[<a href="http://xdebug.org/docs/all_settings#file_link_format" target="_blank">' . JText::_('PLG_DEBUG_LINK_FORMAT') . '</a>]</div>';
					}
				}

				$htmlAccordions = JHtml::_(
					'bootstrap.startAccordion', 'dbg_query_' . $id, array(
						'active' => ($info[$id]->hasWarnings ? ('dbg_query_explain_' . $id) : '')
					)
				);

				$htmlAccordions .= JHtml::_('bootstrap.addSlide', 'dbg_query_' . $id, JText::_('PLG_DEBUG_EXPLAIN'), 'dbg_query_explain_' . $id)
					. $info[$id]->explain
					. JHtml::_('bootstrap.endSlide');

				$htmlAccordions .= JHtml::_('bootstrap.addSlide', 'dbg_query_' . $id, $title, 'dbg_query_profile_' . $id)
					. $htmlProfile
					. JHtml::_('bootstrap.endSlide');

				if ($htmlCallStack)
				{
					$htmlAccordions .= JHtml::_('bootstrap.addSlide', 'dbg_query_' . $id, JText::_('PLG_DEBUG_CALL_STACK'), 'dbg_query_callstack_' . $id)
						. $htmlCallStack
						. JHtml::_('bootstrap.endSlide');
				}

				$htmlAccordions .= JHtml::_('bootstrap.endAccordion');

				$did = md5($query);

				if (isset($duplicates[$did]))
				{
					$dups = array();

					foreach ($duplicates[$did] as $dup)
					{
						if ($dup != $id)
						{
							$dups[] = '<a href="#dbg-query-' . ($dup + 1) . '">#' . ($dup + 1) . '</a>';
						}
					}

					$htmlQuery = '<div class="alert alert-error">' . JText::_('PLG_DEBUG_QUERY_DUPLICATES') . ': ' . implode('&nbsp; ', $dups) . '</div>'
						. '<pre class="alert hasTooltip" title="' . JHtml::tooltipText('PLG_DEBUG_QUERY_DUPLICATES_FOUND') . '">' . $text . '</pre>';
				}
				else
				{
					$htmlQuery = '<pre>' . $text . '</pre>';
				}

				$list[] = '<a name="dbg-query-' . ($id + 1) . '"></a>'
					. $htmlTiming
					. $htmlBar
					. $htmlQuery
					. $htmlAccordions;
			}
			else
			{
				$list[] = '<pre>' . $text . '</pre>';
			}
		}

		$totalTime = 0;

		foreach (JProfiler::getInstance('Application')->getMarks() as $mark)
		{
			$totalTime += $mark->time;
		}

		if ($totalQueryTime > ($totalTime * 0.25))
		{
			$labelClass = 'label-important';
		}
		elseif ($totalQueryTime < ($totalTime * 0.15))
		{
			$labelClass = 'label-success';
		}
		else
		{
			$labelClass = 'label-warning';
		}

		$html = array();

		$html[] = '<h4>' . JText::sprintf('PLG_DEBUG_QUERIES_LOGGED', $db->getCount())
			. sprintf(' <span class="label ' . $labelClass . '">%.1f&nbsp;ms</span>', ($totalQueryTime)) . '</h4><br />';

		if ($total_duplicates)
		{
			$html[] = '<div class="alert alert-error">'
				. '<h4>' . JText::sprintf('PLG_DEBUG_QUERY_DUPLICATES_TOTAL_NUMBER', $total_duplicates) . '</h4>';

			foreach ($duplicates as $dups)
			{
				$links = array();

				foreach ($dups as $dup)
				{
					$links[] = '<a href="#dbg-query-' . ($dup + 1) . '">#' . ($dup + 1) . '</a>';
				}

				$html[] = '<div>' . JText::sprintf('PLG_DEBUG_QUERY_DUPLICATES_NUMBER', count($links)) . ': ' . implode('&nbsp; ', $links) . '</div>';
			}

			$html[] = '</div>';
		}

		$html[] = '<ol><li>' . implode('<hr /></li><li>', $list) . '<hr /></li></ol>';

		if (!$this->params->get('query_types', 1))
		{
			return implode('', $html);
		}

		// Get the totals for the query types:
		$totalSelectQueryTypes = count($selectQueryTypeTicker);
		$totalOtherQueryTypes = count($otherQueryTypeTicker);
		$totalQueryTypes = $totalSelectQueryTypes + $totalOtherQueryTypes;

		$html[] = '<h4>' . JText::sprintf('PLG_DEBUG_QUERY_TYPES_LOGGED', $totalQueryTypes) . '</h4>';

		if ($totalSelectQueryTypes)
		{
			$html[] = '<h5>' . JText::_('PLG_DEBUG_SELECT_QUERIES') . '</h5>';

			arsort($selectQueryTypeTicker);

			$list = array();

			foreach ($selectQueryTypeTicker as $query => $occurrences)
			{
				$list[] = '<pre>'
					. JText::sprintf('PLG_DEBUG_QUERY_TYPE_AND_OCCURRENCES', $this->highlightQuery($query), $occurrences)
					. '</pre>';
			}

			$html[] = '<ol><li>' . implode('</li><li>', $list) . '</li></ol>';
		}

		if ($totalOtherQueryTypes)
		{
			$html[] = '<h5>' . JText::_('PLG_DEBUG_OTHER_QUERIES') . '</h5>';

			arsort($otherQueryTypeTicker);

			$list = array();

			foreach ($otherQueryTypeTicker as $query => $occurrences)
			{
				$list[] = '<pre>'
					. JText::sprintf('PLG_DEBUG_QUERY_TYPE_AND_OCCURRENCES', $this->highlightQuery($query), $occurrences)
					. '</pre>';
			}

			$html[] = '<ol><li>' . implode('</li><li>', $list) . '</li></ol>';
		}

		return implode('', $html);
	}

	/**
	 * @param   array    &$bars  Array of bar data
	 * @param   string   $class  Optional class for items
	 * @param   integer  $id     Id if the bar to highlight
	 *
	 * @return  string
	 *
	 * @since   3.1.2
	 */
	protected function renderBars(&$bars, $class = '', $id = '')
	{
		$html = array();

		foreach ($bars as $i => $bar)
		{
			if (isset($bar->pre) && $bar->pre)
			{
				$html[] = '<div class="dbg-bar-spacer" style="width:' . $bar->pre . '%;"></div>';
			}

			$barClass = trim('bar dbg-bar ' . (isset($bar->class) ? $bar->class : ''));

			if ($id != '' && $i == $id)
			{
				$barClass .= ' dbg-bar-active';
			}

			$tip = '';

			if (isset($bar->tip) && $bar->tip)
			{
				$barClass .= ' hasTooltip';
				$tip = JHtml::tooltipText($bar->tip, '', 0);
			}

			$html[] = '<a class="bar dbg-bar ' . $barClass . '" title="' . $tip . '" style="width: ' . $bar->width . '%;" href="#dbg-' . $class . '-' . ($i + 1) . '"></a>';
		}

		return '<div class="progress dbg-bars dbg-bars-' . $class . '">' . implode('', $html) . '</div>';
	}

	/**
	 * @param   array    $table
	 * @param   boolean  $hasWarnings  Changes value to true if warnings are displayed, otherwise untouched
	 *
	 * @return  string
	 *
	 * @since   3.1.2
	 */
	protected function tableToHtml($table, &$hasWarnings)
	{
		if (!$table)
		{
			return null;
		}

		$html = array();

		$html[] = '<table class="table table-striped dbg-query-table"><tr>';

		foreach (array_keys($table[0]) as $k)
		{
			$html[] = '<th>' . htmlspecialchars($k) . '</th>';
		}

		$html[] = '</tr>';

		$durations = array();

		foreach ($table as $tr)
		{
			if (isset($tr['Duration']))
			{
				$durations[] = $tr['Duration'];
			}
		}

		rsort($durations, SORT_NUMERIC);

		foreach ($table as $tr)
		{
			$html[] = '<tr>';

			foreach ($tr as $k => $td)
			{
				if ($td === null)
				{
					// Display null's as 'NULL':
					$td = 'NULL';
				}

				// Treat special columns:
				if ($k == 'Duration')
				{
					if ($td >= 0.001 && ($td == $durations[0] || (isset($durations[1]) && $td == $durations[1])))
					{
						// Duration column with duration value of more than 1 ms and within 2 top duration in SQL engine: Highlight warning:
						$html[] = '<td class="dbg-warning">';
						$hasWarnings = true;
					}
					else
					{
						$html[] = '<td>';
					}

					// Display duration in ms with the unit instead of seconds:
					$html[] = sprintf('%.1f&nbsp;ms', $td * 1000);
				}
				elseif ($k == 'key')
				{
					if ($td === 'NULL')
					{
						// Displays query parts which don't use a key with warning:
						$html[] = '<td><strong>' . '<span class="dbg-warning hasTooltip" title="' . JHtml::tooltipText('PLG_DEBUG_WARNING_NO_INDEX_DESC') . '">' . JText::_('PLG_DEBUG_WARNING_NO_INDEX') . '</span>' . '</strong>';
						$hasWarnings = true;
					}
					else
					{
						$html[] = '<td><strong>' . htmlspecialchars($td) . '</strong>';
					}
				}
				elseif ($k == 'Extra')
				{
					$htmlTd = htmlspecialchars($td);

					// Replace spaces with nbsp for less tall tables displayed:
					$htmlTd = preg_replace('/([^;]) /', '\1&nbsp;', $htmlTd);

					// Displays warnings for "Using filesort":
					$htmlTdWithWarnings = str_replace('Using&nbsp;filesort', '<span class="dbg-warning hasTooltip" title="' . JHtml::tooltipText('PLG_DEBUG_WARNING_USING_FILESORT_DESC') . '">' . JText::_('PLG_DEBUG_WARNING_USING_FILESORT') . '</span>', $htmlTd);

					if ($htmlTdWithWarnings !== $htmlTd)
					{
						$hasWarnings = true;
					}

					$html[] = '<td>' . $htmlTdWithWarnings;
				}
				else
				{
					$html[] = '<td>' . htmlspecialchars($td);
				}

				$html[] = '</td>';
			}

			$html[] = '</tr>';
		}

		$html[] = '</table>';

		return implode('', $html);
	}

	/**
	 * Disconnect-handler for database to collect profiling and explain information
	 *
	 * @param   JDatabaseDriver  &$db  Database object
	 *
	 * @return  void
	 *
	 * @since   3.1.2
	 */
	public function mysqlDisconnectHandler(&$db)
	{
		$db->setDebug(false);

		$dbVersion5037 = (strncmp($db->name, 'mysql', 5) == 0) && version_compare($db->getVersion(), '5.0.37', '>=');

		if ($dbVersion5037)
		{
			// Run a SHOW PROFILE query:
			$db->setQuery('SHOW PROFILES'); //SHOW PROFILE ALL FOR QUERY ' . (int) ($k+1));
			$this->sqlShowProfiles = $db->loadAssocList();

			if ($this->sqlShowProfiles)
			{
				foreach ($this->sqlShowProfiles as $qn)
				{
					$db->setQuery('SHOW PROFILE FOR QUERY ' . (int) ($qn['Query_ID']));
					$this->sqlShowProfileEach[(int) ($qn['Query_ID'] - 1)] = $db->loadAssocList();
				}
			}
		}

		if (in_array($db->name, array('mysqli', 'mysql', 'postgresql')))
		{
			$log = $db->getLog();

			foreach ($log as $k => $query)
			{
				$dbVersion56 = (strncmp($db->name, 'mysql', 5) == 0) && version_compare($db->getVersion(), '5.6', '>=');

				if ((stripos($query, 'select') === 0) || ($dbVersion56 && ((stripos($query, 'delete') === 0) || (stripos($query, 'update') === 0))))
				{
					$db->setQuery('EXPLAIN ' . ($dbVersion56 ? 'EXTENDED ' : '') . $query);
					$this->explains[$k] = $db->loadAssocList();
				}
			}
		}
	}

	/**
	 * Displays errors in language files.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayLanguageFilesInError()
	{
		$errorfiles = JFactory::getLanguage()->getErrorFiles();

		if (!count($errorfiles))
		{
			return '<p>' . JText::_('JNONE') . '</p>';
		}

		$html = array();

		$html[] = '<ul>';

		foreach ($errorfiles as $file => $error)
		{
			$html[] = '<li>' . $this->formatLink($file) . str_replace($file, '', $error) . '</li>';
		}

		$html[] = '</ul>';

		return implode('', $html);
	}

	/**
	 * Display loaded language files.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayLanguageFilesLoaded()
	{
		$html = array();

		$html[] = '<ul>';

		foreach (JFactory::getLanguage()->getPaths() as /* $extension => */ $files)
		{
			foreach ($files as $file => $status)
			{
				$html[] = '<li>';

				$html[] = ($status)
					? JText::_('PLG_DEBUG_LANG_LOADED')
					: JText::_('PLG_DEBUG_LANG_NOT_LOADED');

				$html[] = ' : ';
				$html[] = $this->formatLink($file);
				$html[] = '</li>';
			}
		}

		$html[] = '</ul>';

		return implode('', $html);
	}

	/**
	 * Display untranslated language strings.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayUntranslatedStrings()
	{
		$stripFirst = $this->params->get('strip-first');
		$stripPref = $this->params->get('strip-prefix');
		$stripSuff = $this->params->get('strip-suffix');

		$orphans = JFactory::getLanguage()->getOrphans();

		if (!count($orphans))
		{
			return '<p>' . JText::_('JNONE') . '</p>';
		}

		ksort($orphans, SORT_STRING);

		$guesses = array();

		foreach ($orphans as $key => $occurance)
		{
			if (is_array($occurance) && isset($occurance[0]))
			{
				$info = $occurance[0];
				$file = ($info['file']) ? $info['file'] : '';

				if (!isset($guesses[$file]))
				{
					$guesses[$file] = array();
				}

				// Prepare the key
				if (($pos = strpos($info['string'], '=')) > 0)
				{
					$parts = explode('=', $info['string']);
					$key = $parts[0];
					$guess = $parts[1];
				}
				else
				{
					$guess = str_replace('_', ' ', $info['string']);

					if ($stripFirst)
					{
						$parts = explode(' ', $guess);

						if (count($parts) > 1)
						{
							array_shift($parts);
							$guess = implode(' ', $parts);
						}
					}

					$guess = trim($guess);

					if ($stripPref)
					{
						$guess = trim(preg_replace(chr(1) . '^' . $stripPref . chr(1) . 'i', '', $guess));
					}

					if ($stripSuff)
					{
						$guess = trim(preg_replace(chr(1) . $stripSuff . '$' . chr(1) . 'i', '', $guess));
					}
				}

				$key = trim(strtoupper($key));
				$key = preg_replace('#\s+#', '_', $key);
				$key = preg_replace('#\W#', '', $key);

				// Prepare the text
				$guesses[$file][] = $key . '="' . $guess . '"';
			}
		}

		$html = array();

		foreach ($guesses as $file => $keys)
		{
			$html[] = "\n\n# " . ($file ? $this->formatLink($file) : JText::_('PLG_DEBUG_UNKNOWN_FILE')) . "\n\n";
			$html[] = implode("\n", $keys);
		}

		return '<pre>' . implode('', $html) . '</pre>';
	}

	/**
	 * Simple highlight for SQL queries.
	 *
	 * @param   string  $query  The query to highlight
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function highlightQuery($query)
	{
		$newlineKeywords = '#\b(FROM|LEFT|INNER|OUTER|WHERE|SET|VALUES|ORDER|GROUP|HAVING|LIMIT|ON|AND|CASE)\b#i';

		$query = htmlspecialchars($query, ENT_QUOTES);

		$query = preg_replace($newlineKeywords, '<br />&#160;&#160;\\0', $query);

		$regex = array(

			// Tables are identified by the prefix
			'/(=)/'
			=> '<b class="dbg-operator">$1</b>',

			// All uppercase words have a special meaning
			'/(?<!\w|>)([A-Z_]{2,})(?!\w)/x'
			=> '<span class="dbg-command">$1</span>',

			// Tables are identified by the prefix
			'/(' . JFactory::getDbo()->getPrefix() . '[a-z_0-9]+)/'
			=> '<span class="dbg-table">$1</span>'

		);

		$query = preg_replace(array_keys($regex), array_values($regex), $query);

		$query = str_replace('*', '<b style="color: red;">*</b>', $query);

		return $query;
	}

	/**
	 * Render the backtrace.
	 *
	 * Stolen from JError to prevent it's removal.
	 *
	 * @param   Exception  $error  The error
	 *
	 * @return  string     Contents of the backtrace
	 *
	 * @since   2.5
	 */
	protected function renderBacktrace($error)
	{
		$backtrace = $error->getTrace();

		$html = array();

		if (is_array($backtrace))
		{
			$j = 1;

			$html[] = '<table cellpadding="0" cellspacing="0">';

			$html[] = '<tr>';
			$html[] = '<td colspan="3"><strong>Call stack</strong></td>';
			$html[] = '</tr>';

			$html[] = '<tr>';
			$html[] = '<th>#</th>';
			$html[] = '<th>Function</th>';
			$html[] = '<th>Location</th>';
			$html[] = '</tr>';

			for ($i = count($backtrace) - 1; $i >= 0; $i--)
			{
				$link = '&#160;';

				if (isset($backtrace[$i]['file']))
				{
					$link = $this->formatLink($backtrace[$i]['file'], $backtrace[$i]['line']);
				}

				$html[] = '<tr>';
				$html[] = '<td>' . $j . '</td>';

				if (isset($backtrace[$i]['class']))
				{
					$html[] = '<td>' . $backtrace[$i]['class'] . $backtrace[$i]['type'] . $backtrace[$i]['function'] . '()</td>';
				}
				else
				{
					$html[] = '<td>' . $backtrace[$i]['function'] . '()</td>';
				}

				$html[] = '<td>' . $link . '</td>';

				$html[] = '</tr>';
				$j++;
			}

			$html[] = '</table>';
		}

		return implode('', $html);
	}

	/**
	 * Replaces the Joomla! root with "JROOT" to improve readability.
	 * Formats a link with a special value xdebug.file_link_format
	 * from the php.ini file.
	 *
	 * @param   string  $file  The full path to the file.
	 * @param   string  $line  The line number.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function formatLink($file, $line = '')
	{
		$link = str_replace(JPATH_ROOT, 'JROOT', $file);
		$link .= ($line) ? ':' . $line : '';

		if ($this->linkFormat)
		{
			$href = $this->linkFormat;
			$href = str_replace('%f', $file, $href);
			$href = str_replace('%l', $line, $href);

			$html = '<a href="' . $href . '">' . $link . '</a>';
		}
		else
		{
			$html = $link;
		}

		return $html;
	}

	/**
	 * Store log messages so they can be displayed later.
	 * This function is passed log entries by JLogLoggerCallback.
	 *
	 * @param   JLogEntry  $entry  A log entry.
	 *
	 * @since   3.1
	 */
	public function logger(JLogEntry $entry)
	{
		$this->logEntries[] = $entry;
	}

	/**
	 * Display log messages
	 *
	 * @return  string
	 *
	 * @since   3.1
	 */
	protected function displayLogs()
	{
		$priorities = array(
			JLog::EMERGENCY => 'EMERGENCY',
			JLog::ALERT => 'ALERT',
			JLog::CRITICAL => 'CRITICAL',
			JLog::ERROR => 'ERROR',
			JLog::WARNING => 'WARNING',
			JLog::NOTICE => 'NOTICE',
			JLog::INFO => 'INFO',
			JLog::DEBUG => 'DEBUG'
		);

		$out = array();

		foreach ($this->logEntries as $entry)
		{
			$out[] = '<h5>' . $priorities[$entry->priority] . ' - ' . $entry->category . ' </h5><code>' . $entry->message . '</code>';
		}

		return implode('<br /><br />', $out);
	}
}
