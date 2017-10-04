<?php
/**
 * @category   Emarsys
 * @package    Emarsys_Emarsys
 * @copyright  Copyright (c) 2017 Emarsys. (http://www.emarsys.net/)
 */

namespace Emarsys\Emarsys\Block\Adminhtml\Customerexport\Edit\Tab;

use Magento\Backend\Block\Widget\Grid\Extended;

class Form extends \Magento\Backend\Block\Widget\Form\Generic
{

    /**
     * @var \Magento\ImportExport\Model\Source\Export\FormatFactory
     */
    protected $_formatFactory;

    public function __construct(
        \Magento\Backend\Block\Widget\Context $context,
        \Magento\Backend\Model\Auth\Session $session,
        \Magento\Framework\Data\FormFactory $formFactory,
        \Magento\Framework\Registry $registry,
        \Magento\ImportExport\Model\Source\Export\FormatFactory $formatFactory,
        \Magento\Framework\App\Request\Http $request,
        Extended $extended,
        array $data = []
    ) {
    
        parent::__construct($context, $registry, $formFactory, $data);
        $this->session = $session;
        $this->admin = $context->getBackendSession();
        $this->storeManager = $context->getStoreManager();
        $this->_formFactory = $formFactory;
        $this->_registry = $registry;
        $this->extended = $extended;
        $this->getRequest = $request;
        $this->scopeConfigInterface = $context->getScopeConfig();
        $this->_formatFactory = $formatFactory;
    }

    /**
     * Init form
     */
    protected function _prepareForm()
    {
        $form = $this->_formFactory->create();
        $this->setForm($form);
        $fieldset = $form->addFieldset("support_form", ["legend" => '']);
        $values = [];
        $values['customer'] = 'Customer';
        $values['subscriber'] = 'Subscriber';
        $smartInsightEnable = $this->scopeConfigInterface->getValue('smart_insight/smart_insight/smartinsight_enabled');
        if ($smartInsightEnable == 1) {
            $values['order'] = 'Order';
        }
        $productExportStatus = $this->scopeConfigInterface->getValue('emarsys_predict/feed_export/enable_nightly_product_feed');
        if ($productExportStatus == 1 || $smartInsightEnable == 1) {
            $values['product'] = 'Product';
        }
        $fieldset->addField("entitytype", "select", [
            'label' => 'Export Entity Type',
            'name' => 'entity_type',
            'values' => $values,
            'style' => 'width:200px',
            'value' => 'customer',
            'onchange' => "bulkExport(this.value)",
        ]);

        $fieldset->addField("fromdate", "date", [
            'label' => 'From',
            'name' => 'from_date',
            'type' => 'datetime',
            'date_format' => $this->_localeDate->getDateFormat(\IntlDateFormatter::SHORT),
            'time_format' => $this->_localeDate->getTimeFormat(\IntlDateFormatter::SHORT),
            'style' => 'width:200px',

        ]);

        $fieldset->addField("todate", "date", [
            'label' => 'To',
            'name' => 'to_date',
            'date_format' => $this->_localeDate->getDateFormat(\IntlDateFormatter::SHORT),
            'time_format' => $this->_localeDate->getTimeFormat(\IntlDateFormatter::SHORT),
            'time' => 'true',
            'style' => 'width:200px',
        ]);

        $fieldset->addField(
            'file_format',
            'select',
            [
                'name' => 'file_format',
                'style' => 'width:200px',
                'title' => __('Export To'),
                'label' => __('Export To'),
                'required' => false,
                'values' => $this->_formatFactory->create()->toOptionArray()
            ]
        );
        return parent::_prepareForm();
    }
}
