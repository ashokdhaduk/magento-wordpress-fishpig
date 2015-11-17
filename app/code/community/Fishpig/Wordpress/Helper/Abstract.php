<?php
/**
 * @category    Fishpig
 * @package     Fishpig_Wordpress
 * @license     http://fishpig.co.uk/license.txt
 * @author      Ben Tideswell <help@fishpig.co.uk>
 */

class Fishpig_Wordpress_Helper_Abstract extends Mage_Core_Helper_Abstract
{
	/**
	 * Internal cache variable
	 *
	 * @var array
	 */
	static protected $_cache = array();

	/**
	 * Returns the URL used to access your Wordpress frontend
	 *
	 * @param string|null $extra = null
	 * @param array $params = array
	 * @return string
	 */
	public function getUrl($extra = null, array $params = array())
	{
		if (count($params) > 0) {
			$extra = trim($extra, '/') . '/';
			
			foreach($params as $key => $value) {
				$extra .= $key . '/' . $value . '/';
			}
		}
		
		if ($this->isFullyIntegrated()) {
			$params = array(
				'_direct' 	=> ltrim($this->getBlogRoute() . '/' . ltrim($extra, '/'), '/'),
				'_secure' 	=> false,
				'_nosid' 	=> true,
				'_store'		=> Mage::app()->getStore()->getId(),
			);
			
			if (Mage::app()->getStore()->getCode() == 'admin') {
				if ($storeCode = Mage::app()->getRequest()->getParam('store')) {
					$params['_store'] = $storeCode;
				}
				else {
					$params['_store'] = $this->getDefaultStore(Mage::app()->getRequest()->getParam('website', null))->getId();
				}
			}
			
			$url = $this->_getUrl('', $params);
		}
		else {
			$url = $this->getWpOption('home') . '/' . ltrim($extra, '/');
		}
	
		return htmlspecialchars($url);
	}
	
	/**
	 * Returns the blog route selected in the Magento config
	 *
	 * @return string|null
	*/
	public function getBlogRoute()
	{
		if ($this->isFullyIntegrated()) {
			if (!$this->_isCached('blog_route')) {
				$transport = new Varien_Object(array('blog_route' => trim(strtolower(Mage::getStoreConfig('wordpress/integration/route', Mage::helper('wordpress/app')->getStore()->getId())), '/')));
			
				Mage::dispatchEvent('wordpress_get_blog_route', array('transport' => $transport));
			
				$this->_cache('blog_route', $transport->getBlogRoute());
			}
			
			return $this->_cached('blog_route');
		}
		
		return null;
	}
	
	/**
	  * Determine whether the extension is fully integrated
	  * If false, semi-integration is being used
	  *
	  * @return bool
	  */
	public function isFullyIntegrated()
	{
		return Mage::getStoreConfigFlag('wordpress/integration/full', Mage::helper('wordpress/app')->getStore()->getId());
	}
	
	public function getCustomizerData()
	{
		if (Mage::app()->getRequest()->getPost('wp_customize') === 'on') {
			if ($data = Mage::app()->getRequest()->getPost('customized')) {
				if ($data = json_decode(stripslashes($data), true)) {
					return $data;
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Gets a Wordpress option based on it's option name
	 *
	 * @param string $optionName
	 * @param mixed $default = null
	 * @return string
	 */
	public function getWpOption($key, $default = null)
	{
		if ($data = $this->getCustomizerData()) {
			if (isset($data[$key])) {
				return $data[$key];
			}
		}
			
		$db = $this instanceof Fishpig_Wordpress_Helper_App
			? $this->getDbConnection()
			: Mage::helper('wordpress/app')->getDbConnection();
			
		if ($db === false) {
			return false;
		}
		
		$cacheKey = 'wp_option_' . $key;
		
		if ($this->_isCached($cacheKey)) {
			return $this->_cached($cacheKey);
		}
		
		$this->_cache($cacheKey, $default);
		
		try {


			$select = $db->select()
				->from(Mage::getSingleton('core/resource')->getTableName('wordpress/option'), 'option_value')
				->where('option_name = ?', $key)
				->limit(1);

			if ($value = $db->fetchOne($select)) {
				$this->_cache($cacheKey, $value);
				
				return $value;
			}

			return $default;
		}
		catch (Exception $e) {
			$this->log($e->getMessage());
		}
		
		return false;
	}
	
	/**
	  * Logs an error to the Wordpress error log
	  *
	  */
	public function log($message, $serious = true)
	{
		if (is_object($message) && $message instanceof Exception) {
			$message = $message->__toString();
		}
		
		if ($message = trim($message)) {
			return Mage::log($message, null, 'wordpress.log', true);
		}
	}

	/**
	 * Retrieve the default store model
	 *
	 * @return Mage_Core_Model_Store
	 */
	public function getDefaultStore($websiteCode = null)
	{
		if (!$this->_isCached('default_store')) {	
			$resource = Mage::getSingleton('core/resource');
			$connection = $resource->getConnection('core_read');
			$select = $connection->select()
				->from(array('_store_table' => $resource->getTableName('core/store')), 'store_id')
				->where('_store_table.store_id > ?', 0)
				->where('_store_table.code != ?', 'admin')
				->limit(1)
				->order('_store_table.sort_order ASC');
			
			if (!is_null($websiteCode)) {
				$select->join(
					array('_website_table' => $resource->getTableName('core/website')),
					$connection->quoteInto('`_website_table`.`website_id`=`_store_table`.`website_id` AND `_website_table`.`code`=?', $websiteCode),
					''
				);
			}
			
			$store = Mage::getModel('core/store')->load($connection->fetchOne($select));
			
			if (!$store->getId() && !is_null($websiteCode)) {
				return $this->getDefaultStore();
			}
			
			$this->_cache('default_store', $store);
		}
		
		return $this->_cached('default_store');
	}
	
	/**
	 * Store a value in the cache
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return $this;
	 */
	protected function _cache($key, $value)
	{
		self::$_cache[$key] = $value;
		
		return $this;
	}
	
	/**
	 * Determine whether there is a value in the cache for the key
	 *
	 * @param string $key
	 * @return bool
	 */
	protected function _isCached($key)
	{
		return isset(self::$_cache[$key]);
	}
	
	/**
	 * Retrieve a value from the cache
	 *
	 * @param string $key
	 * @param mixed $default = null
	 * @return mixed
	 */
	protected function _cached($key, $default = null)
	{
		if ($this->_isCached($key)) {
			return self::$_cache[$key];
		}
		
		return $default;
	}
	
	/**
	 * Retrieve a plugin option
	 *
	 * @param string $plugin
	 * @param string $key = null
	 * @return mixed
	 */
	public function getPluginOption($plugin, $key = null)
	{
		$options = $this->getWpOption($plugin);
		
		if (($data = @unserialize($options)) !== false) {
			if (is_null($key)) {
				return $data;
			}

			return isset($data[$key])
				? $data[$key]
				: null;
		}
		
		return $options;
	}
}
