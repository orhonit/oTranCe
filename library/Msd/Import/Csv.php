<?php
/**
 * This file is part of oTranCe released under the GNU GPL 3 license
 * http://www.oTranCe.de
 *
 * @package         oTranCe
 * @subpackage      Importer
 * @version         SVN: $
 * @author          $Author: $
 */

/**
 * Generic comma seperated file importer
 *
 * @package         oTranCe
 * @subpackage      Importer
 */

class Msd_Import_Csv implements Msd_Import_Interface
{
    /**
     * @var string
     */
    private $_data;

    /**
     * @var array
     */
    private $_lines;

    /**
     * @var array
     */
    private $_currentLine;

    /**
     * Will hold detected and extracted data
     * @var array
     */
    protected $_extractedData = array();

    /**
     * Key -> Value separator
     * @var string
     */
    protected $_separator = ',';

    /**
     * Analyze data and return exracted key=>value pairs
     *
     * @abstract
     * @param string $data String data to analyze
     *
     * @return array Extracted key => value-Array
     */
    public function extract($data)
    {
        $this->_data = $data;
        unset($data);
        $this->_extractedData = array();

        $this->_lines = explode("\n", $this->_data);

        for ($i = 0; $i < count($this->_lines); $i++) {
            $this->_currentLine = explode($this->_separator, $this->_lines[$i]);
            $this->_extractedData[$this->_currentLine[0]] = $this->_currentLine[1];
        }

        return $this->_extractedData;
    }

}