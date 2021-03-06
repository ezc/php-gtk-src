<?php
// Call GtkCellViewTest::main() if this source file is executed directly.
if (!defined("PHPUnit_MAIN_METHOD")) {
    define("PHPUnit_MAIN_METHOD", "GtkCellViewTest::main");
}

require_once "PHPUnit/Framework/TestCase.php";
require_once "PHPUnit/Framework/TestSuite.php";

// You may remove the following line when all tests have been implemented.
require_once "PHPUnit/Framework/IncompleteTestError.php";



/**
 * Test class for GtkCellView.
 * Generated by PHPUnit_Util_Skeleton on 2006-03-07 at 13:26:40.
 */
class GtkCellViewTest extends PHPUnit_Framework_TestCase {
    /**
     * Runs the test methods of this class.
     *
     * @access public
     * @static
     */
    public static function main() {
        require_once "PHPUnit/TextUI/TestRunner.php";

        $suite  = new PHPUnit_Framework_TestSuite("GtkCellViewTest");
        $result = PHPUnit_TextUI_TestRunner::run($suite);
    }

    /**
     * Sets up the fixture, for example, open a network connection.
     * This method is called before a test is executed.
     *
     * @access protected
     */
    protected function setUp() {
        $this->cv = new GtkCellView();
        $mod = new GtkListStore(Gtk::TYPE_STRING, Gtk::TYPE_STRING);
        $this->cv->set_model($mod);
        $mod->append(array('a', '1'));
        $mod->append(array('b', '2'));
        $mod->append(array('c', '3'));
    }

    /**
     * Tears down the fixture, for example, close a network connection.
     * This method is called after a test is executed.
     *
     * @access protected
     */
    protected function tearDown() {
    }

    /**
     * @todo Implement testGet_cell_renderers().
     */
    public function testGet_cell_renderers() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testGet_displayed_row().
     */
    public function testGet_displayed_row() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     *
     */
    public function testGet_size_of_row() {
        //path 0 is first row
        $req = $this->cv->get_size_of_row('0');
        $this->assertNotNull($req);
        $this->assertType('GtkRequisition', $req);
    }

    /**
     * @todo Implement testSet_background_color().
     */
    public function testSet_background_color() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testSet_displayed_row().
     */
    public function testSet_displayed_row() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testSet_model().
     */
    public function testSet_model() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testAdd_attribute().
     */
    public function testAdd_attribute() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testClear().
     */
    public function testClear() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testClear_attributes().
     */
    public function testClear_attributes() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testPack_end().
     */
    public function testPack_end() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testPack_start().
     */
    public function testPack_start() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testReorder().
     */
    public function testReorder() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }

    /**
     * @todo Implement testSet_attributes().
     */
    public function testSet_attributes() {
        // Remove the following line when you implement this test.
        throw new PHPUnit_Framework_IncompleteTestError;
    }
}

// Call GtkCellViewTest::main() if this source file is executed directly.
if (PHPUnit_MAIN_METHOD == "GtkCellViewTest::main") {
    GtkCellViewTest::main();
}
?>
