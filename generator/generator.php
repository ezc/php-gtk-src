<?php
/*
 * PHP-GTK - The PHP language bindings for GTK+
 *
 * Copyright (C) 2001-2004 Andrei Zmievski <andrei@php.net>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * 
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/* $Id$ */

/*
 * Significant portions of this generator are based on the pygtk code generator
 * developed by James Henstridge <jamesh@daa.com.au>.
 *
 */

set_time_limit(300);

// override the default PHP 8Mb as this script tends to use alot more
// and hopefully reduce the support questions a bit..
ini_set('memory_limit','64M');

require "Getopt.php";
require "override.php";
require "arg_types.php";
require "scheme.php";
require "templates.php";
require "array_printf.php";
require "lineoutput.php";

class Generator {
    var $parser             = null;
    var $overrides          = null;
    var $prefix             = null;
    var $lprefix            = null;
    var $function_class     = null;
    var $logfile            = null;
    var $diversions         = null;

    var $constants          = '';
    var $template_map       = array('Object_Def' => array('constructor' => Templates::constructor_body,
                                                          'static_constructor' => Templates::static_constructor_body,
                                                          'method' => Templates::method_body,
                                                          'prop' => Templates::prop_access),
                                    'Boxed_Def'  => array('constructor' => Templates::boxed_constructor_body,
                                                          'static_constructor' => Templates::boxed_static_constructor_body,
                                                          'method' => Templates::boxed_method_body,
                                                          'prop' => Templates::boxed_prop_access),
                                   );
    var $handlers           = array('read_property', 'write_property', 'get_properties');

    function Generator(&$parser, &$overrides, $prefix, $function_class)
    {
        $this->parser    = &$parser;
        $this->overrides = &$overrides;
        $this->prefix    = ucfirst($prefix);
        $this->lprefix   = strtolower($prefix);
        $this->function_class = $function_class;
    }

    function set_logfile($logfile) {
        $this->logfile = fopen($logfile, 'w');
    }
    
    function log_print()
    {
        $args = func_get_args();
        if (count($args) == 0) return;

        $format = array_shift($args);

        $output = vsprintf($format, $args);
        echo $output;
        fwrite($this->logfile, $output);
    }

    function log()
    {
        $args = func_get_args();
        if (count($args) == 0) return;

        $format = array_shift($args);

        fwrite($this->logfile, vsprintf($format, $args));
    }

    function divert()
    {
        $args = func_get_args();
        if (count($args) < 2) return;

        list ($divert_id, $format) = $args;

        @$this->diversions[$divert_id] .= vsprintf($format, array_slice($args, 2));
    }

    function register_types($parser = null)
    {
        global  $matcher;

        if (!$parser)
            $parser = $this->parser;

        foreach ($parser->objects as $object) {
            $matcher->register_object($object->c_name, $object->typecode);
        }

        foreach ($parser->enums as $enum) {
            if ($enum->def_type == 'flags')
                $matcher->register_flag($enum->c_name, $enum->typecode);
            else
                $matcher->register_enum($enum->c_name, $enum->typecode);
        }

        foreach ($parser->boxed as $boxed) {
            $matcher->register_boxed($boxed->c_name, $boxed->typecode);
        }
    }


    function write_override($override, $id)
    {
        $args = array_slice(func_get_args(), 1);
        list($lineno, $file_name) = $this->overrides->get_line_info(join('.', $args)); 
        $this->fp->set_line($lineno, $file_name);
        $this->fp->write($override);
        $this->fp->reset_line();
        $this->fp->write("\n\n");
    }


    function write_callable($callable, $template, $handle_return = false, $is_method = false, $dict = array())
    {
        global $matcher;

        if ($callable->varargs) {
            throw new Exception('varargs methods not supported');
        }

        $info = new Wrapper_Info();

        /* need the extra comma for methods */
        if ($is_method) {
            $info->arg_list[] = '';
        }

        foreach ($callable->params as $params_array) {
            list($param_type, $param_name, $param_default, $param_null) = $params_array;

            if (isset($param_default) && strpos($info->specifiers, '|') === false) {
                $info->add_parse_list('|');
            }

            $handler = $matcher->get($param_type);
            $handler->write_param($param_type, $param_name, $param_default, $param_null, $info);
        }

        $dict['return'] = '';
        if ($handle_return) {
            if ($callable->return_type !== null &&
                $callable->return_type != 'none') {
                $dict['return'] = 'php_retval = ';
            }
            $handler = $matcher->get($callable->return_type);
            $handler->write_return($callable->return_type, $callable->caller_owns_return, $info);
        }

        if (isset($callable->deprecated)) {
            $info->pre_code[] = sprintf(Templates::deprecation_msg, $callable->deprecated ? '"'.$callable->deprecated.'"' : 'NULL');
        }

        if (!isset($dict['name'])) {
            $dict['name'] = $callable->name;
        }
        $dict['cname'] = $callable->c_name;
        $dict['var_list'] = $info->get_var_list();
        $dict['specs'] = $info->specifiers;
        $dict['parse_list'] = $info->get_parse_list();
        $dict['arg_list'] = $info->get_arg_list();
        $dict['pre_code'] = $info->get_pre_code();
        $dict['post_code'] = $info->get_post_code();

        return aprintf($template, $dict);
    }


    function write_methods($object)
    {
        $method_defs = array();

        $this->log_print("  %-20s ", "methods");
        $num_written = $num_skipped = 0;

        $methods = $this->parser->find_methods($object);

        $dict['class'] = $object->c_name;
        $dict['scope'] = $object->c_name;
        $dict['typecode'] = $object->typecode;

        switch ($def_type = get_class($object)) {
            case 'Object_Def':
                $dict['cast'] = preg_replace('!_TYPE_!', '_', $object->typecode, 1);
                break;

            case 'Boxed_Def':
                $dict['cast'] = $object->c_name . ' *';
                break;

            default:
                throw new Exception("unhandled definition type");
                break;
        }

        foreach ($methods as $method) {
            $method_name = $method->c_name;
            
            /* skip ignored methods */
            if ($this->overrides->is_ignored($method_name)) continue;

            try {
                if (($overriden = $this->overrides->is_overriden($method_name))) {
                    list($method_name, $method_override, $flags) = $this->overrides->get_override($method_name);
                    $this->write_override($method_override, $method->c_name);
                    if (!isset($method_name))
                        $method_name = $method->name;
                    $method_defs[] = sprintf(Templates::method_entry,
                                             $object->in_module . $object->name,
                                             $method->name, 'NULL', $flags ?  $flags : 'ZEND_ACC_PUBLIC');
                } else {
                    if ($method->static) {
                        $code = $this->write_callable($method, Templates::function_body, true, false, $dict);
                        $flags = 'ZEND_ACC_PUBLIC|ZEND_ACC_STATIC';
                    } else {
                        $template = $this->template_map[get_class($object)]['method'];
                        $code = $this->write_callable($method, $template, true, true, $dict);
                        $flags = 'ZEND_ACC_PUBLIC';
                    }
                    $this->fp->write($code);
                    $method_defs[] = sprintf(Templates::method_entry,
                                             $object->in_module . $object->name,
                                             $method->name, 'NULL', $flags);
                }
                $this->divert("gen", "%s  %-11s %s::%s\n", $overriden ? "%%":"  ", "method", $object->c_name, $method->name);
                $num_written++;
            } catch (Exception $e) {
                $this->divert("notgen", "  %-11s %s::%s: %s\n", "method", $object->c_name, $method->name, $e->getMessage());
                $num_skipped++;
            }
        }

        $this->log_print("(%d written, %d skipped)\n", $num_written, $num_skipped);

        return $method_defs;
    }

    function write_constructor($object)
    {
        $this->log_print("  %-20s ", "constructors");
        $num_written = $num_skipped = 0;

        $ctors = $this->parser->find_constructor($object, $this->overrides);

        $ctor_defs = array();

        if ($ctors) {
            $dict['class'] = $object->c_name;
            $dict['typecode'] = $object->typecode;;
            $first = 1;

            foreach ($ctors as $ctor) {
                $ctor_name = $ctor->c_name;
                if ($first) {
                    $ctor_fe_name = '__construct';
                    $flags = 'ZEND_ACC_PUBLIC';
                    $template_name = 'constructor';
                } else {
                    // remove class name from the constructor name, i.e. turn
                    // gtk_button_new_with_mnemonic into new_with_mnemonic
                    $ctor_fe_name = substr($ctor_name, strlen(convert_typename($ctor->is_constructor_of)));
                    $flags = 'ZEND_ACC_PUBLIC|ZEND_ACC_STATIC';
                    $template_name = 'static_constructor';
                }

                $template = $this->template_map[get_class($object)][$template_name];

                try {
                    if (($overriden = $this->overrides->is_overriden($ctor_name))) {
                        list(, $ctor_override, $ctor_flags) = $this->overrides->get_override($ctor_name);
                        if (!empty($ctor_flags))
                            $flags = $ctor_flags;
                        $this->write_override($ctor_override, $ctor->c_name);
                    } else {
                        $dict['name'] = $ctor_fe_name;
                        $code = $this->write_callable($ctor, $template, false, false, $dict);
                        $this->fp->write($code);
                    }
                    $ctor_defs[] = sprintf(Templates::method_entry,
                                           $ctor->is_constructor_of,
                                           $ctor_fe_name, 'NULL', $flags);
                    $this->divert("gen", "%s  %-11s %s::%s\n", $overriden?"%%":"  ", "constructor", $object->c_name, $ctor_fe_name);
                    $num_written++;
                } catch (Exception $e) {
                    $this->divert("notgen", "  %-11s %s::%s: %s\n", "constructor", $object->c_name, $ctor_fe_name, $e->getMessage());
                    $num_skipped++;
                    // mark class as non-instantiable directly if we were trying
                    // to generate default constructor
                    if ($ctor_fe_name == '__construct') {
                        $ctor_defs[] = sprintf(Templates::function_entry, $ctor_fe_name, 'no_direct_constructor');
                    }
                }
                $first = 0;
            }
        } else {
            // mark class as non-instantiable directly
            $ctor_defs[] = sprintf(Templates::function_entry, '__construct', 'no_direct_constructor');
        }

        $this->log_print("(%d written, %d skipped)\n", $num_written, $num_skipped);
        return $ctor_defs;
    }


    function write_classes()
    {
        $register_classes = '';

        if ($this->parser->functions || $this->parser->enums) {
            $this->log_print("\n%s\n%s\n", $this->prefix, str_repeat('~', 50));
            $func_defs = $this->write_functions();

            $register_classes .= aprintf(Templates::register_class,
                                         array('ce' => $this->lprefix . '_ce',
                                               'class' => $this->prefix,
                                               'methods' => $func_defs ? $this->lprefix . '_methods' : 'NULL',
                                               'parent' => 'NULL',
                                               'ce_flags' => 0,
                                               'propinfo' => 'NULL',
                                               'create_func' => 'NULL',
                                               'typecode' => 0));
        }

        /* GObject's */
        foreach ($this->parser->objects as $object) {
            $reg_info = $this->write_class($object);
            $register_classes .= aprintf(Templates::register_class, $reg_info);
        }

        /* GBoxed */
        foreach ($this->parser->boxed as $object) {
            $reg_info = $this->write_class($object, 'GBoxed');
            $register_classes .= aprintf(Templates::register_boxed, $reg_info);
        }

        $this->fp->write(sprintf(Templates::register_classes,
                                  $this->lprefix,
                                  $register_classes));
    }
    
    function write_prop_handlers($object)
    {
        global $matcher;

        if (!$object->fields) {
            return 'NULL';
        }
        $this->log_print("  %-20s ", "property accessors");
        $num_written = $num_skipped = 0;

        $class = strtolower($object->c_name);
        $read_prefix  = 'phpg_' . $class .'_read_';
        $write_prefix = 'phpg_' . $class .'_write_';

        $prop_defs = array();
        $dict = array();

        switch ($def_type = get_class($object)) {
            case 'Object_Def':
                $dict['cast'] = preg_replace('!_TYPE_!', '_', $object->typecode, 1);
                break;

            case 'Boxed_Def':
                $dict['cast'] = $object->c_name . ' *';
                break;

            default:
                throw new Exception("unhandled definition type");
                break;
        }

        foreach ($object->fields as $field) {
            list($field_type, $field_name) = $field;

            $read_func = "PHPG_PROP_READ_FN($object->c_name, $field_name)";
            $write_func = 'NULL';
            $info = new Wrapper_Info();

            try {
                if ($this->overrides->is_prop_overriden($object->c_name, $field_name)) {
                    $overrides = $this->overrides->get_prop_override($object->c_name, $field_name);
                    if (isset($overrides['read'])) {
                        $this->write_override($overrides['read'], $object->c_name, $field_name, 'read');
                        $this->divert("gen", "%%%%  %-11s %s->%s\n", "reader for", $object->c_name, $field_name);
                    } else {
                        $read_func = 'NULL';
                    }
                    if (isset($overrides['write'])) {
                        $this->write_override($overrides['read'], $object->c_name, $field_name, 'write');
                        $write_func = $write_prefix . $field_name;
                        $this->divert("gen ", "%%%%  %-11s %s->%s\n", "writer for", $object->c_name, $field_name);
                    }
                } else {
                    $handler = $matcher->get($field_type);
                    $handler->write_return($field_type, false, $info);

                    $dict['name'] = $field_name;

                    $this->fp->write(aprintf(Templates::prop_reader,
                                              array('class' => $object->c_name,
                                                    'name' => $field_name,
                                                    'var_list' => $info->get_var_list(),
                                                    'pre_code' => $info->get_pre_code(),
                                                    'post_code' => $info->get_post_code(),
                                                    'prop_access' => aprintf($this->template_map[$def_type]['prop'], $dict)
                                                   )));
                    $this->divert("gen", "    %-11s %s->%s\n", "reader for", $object->c_name, $field_name);
                }
                $prop_defs[] = sprintf(Templates::prop_info_entry, 
                                       $field_name, $read_func, $write_func);
                $num_written++;
            } catch (Exception $e) {
                $this->divert("notgen", "  %-11s %s->%s: %s\n", "reader for", $object->c_name, $field_name, $e->getMessage());
                $num_skipped++;
            }
        }

        $this->log_print("(%d written, %d skipped)\n", $num_written, $num_skipped);

        if ($prop_defs) {
            $this->fp->write(sprintf(Templates::prop_info_header, $class));
            $this->fp->write(join('', $prop_defs));
            $this->fp->write(Templates::prop_info_footer);
            return $class . '_prop_info';
        } else {
            return 'NULL';
        }
    }

    function write_object_handlers($object)
    {
        $handlers = array();

        foreach ($this->handlers as $handler) {
            if ($this->overrides->is_handler_overriden($object->c_name, $handler)) {
                $override = $this->overrides->get_handler_override($object->c_name, $handler);
                $this->write_override($override, $object->c_name, $handler);
                $handlers[] = $handler;
            }
        }

        if (!$handlers)
            return array('NULL', '');

        $dict['class'] = strtolower($object->in_module . $object->name);
        switch ($def_type = get_class($object)) {
            case 'Object_Def':
                $dict['create_func'] = 'phpg_create_gobject';
                break;

            case 'Boxed_Def':
                $dict['create_func'] = 'phpg_create_gboxed';
                break;

            default:
                throw new Exception("unhandled definition type");
                break;
        }

        $this->fp->write(aprintf(Templates::custom_create_func, $dict));
        $create_func = 'phpg_create_' . $dict['class'];

        $extra_reg_info = aprintf(Templates::custom_handlers_init, $dict);
        foreach ($handlers as $handler) {
            $dict['handler'] = $handler;
            $extra_reg_info .= aprintf(Templates::custom_handler_set, $dict);
        }

        return array($create_func, $extra_reg_info);
    }

    function write_class($object)
    {
        $this->log_print("\n%s\n%s\n", $object->c_name, str_repeat('~', 50));

        $object_module = strtolower($object->in_module);
        $object_lname = strtolower($object->name);

        $extra_ref_info = '';

        $ctor_defs = $this->write_constructor($object);
        $method_defs = $this->write_methods($object);
        sort($method_defs);
        if ($ctor_defs) {
            $method_defs = array_merge($ctor_defs, $method_defs);
        }

        if ($method_defs) {
            $this->fp->write(sprintf(Templates::functions_decl, strtolower($object->c_name)));
            $this->fp->write(join('', $method_defs));
            $this->fp->write(Templates::functions_decl_end);
        }

        $prop_info = $this->write_prop_handlers($object);

        list($create_func, $extra_reg_info) = $this->write_object_handlers($object);

        return array('ce' => $object->ce,
                     'class' => $object->in_module . $object->name,
                     'methods' => $method_defs ? strtolower($object->c_name) . '_methods' : 'NULL',
                     'parent' => (get_class($object) == 'Object_Def' && $object->parent) ? strtolower($object->parent) . '_ce' : 'NULL',
                     'ce_flags' => $object->ce_flags ? implode('|', $object->ce_flags) : 0,
                     'typecode' => $object->typecode,
                     'create_func' => $create_func,
                     'extra_reg_info' => $extra_reg_info,
                     'propinfo' => $prop_info);
    }

    function write_constants()
    {
        $enums_code = '';

        foreach ($this->parser->enums as $enum) {
            if ($enum->typecode === null) {
                throw new Exception("unhandled enum type");
                foreach ($enum->values as $nick => $value) {
                }
            } else {
                $enums_code .= sprintf(Templates::register_enum, $enum->def_type,
                                       $enum->typecode, $this->lprefix . '_ce');
            }
        }

        $this->fp->write(sprintf(Templates::register_constants, $this->lprefix, $enums_code . "\n" . $this->overrides->get_constants()));
    }

    function write_functions()
    {
        $func_defs = array();

        $this->log_print("  %-20s", "functions");
        $num_written = $num_skipped = 0;

        $dict['scope'] = $this->prefix;

        foreach ($this->parser->functions as $function) {
            $func_name = $function->name;
            if ($function->name == $function->c_name) {
                $func_name = substr($function->name, strlen($this->lprefix) + 1);
            }
            $dict['name'] = $func_name;

            /* skip ignored methods */
            if ($this->overrides->is_ignored($function->c_name)) continue;

            try {
                if (($overriden = $this->overrides->is_overriden($function->c_name))) {
                    list($func_name, $function_override, $flags) = $this->overrides->get_override($function->c_name);
                    $this->write_override($function_override, $function->c_name);
                    if ($func_name == $function->c_name)
                        $func_name = $function->name;
                    $func_defs[] = sprintf(Templates::method_entry,
                                           $this->prefix,
                                           $func_name, 'NULL', $flags ? $flags : 'ZEND_ACC_PUBLIC|ZEND_ACC_STATIC');
                } else {
                    $code = $this->write_callable($function, Templates::function_body, true, false, $dict);
                    $this->fp->write($code);
                    $func_defs[] = sprintf(Templates::method_entry,
                                           $this->prefix,
                                           $func_name, 'NULL', 'ZEND_ACC_PUBLIC|ZEND_ACC_STATIC');
                }
                $this->divert("gen", "%s  %-11s %s::%s\n", $overriden?"%%":"  ", "function", $this->prefix, $function->name);
                $num_written++;
            } catch (Exception $e) {
                $this->divert("notgen", "  %-11s %s::%s: %s\n", "function", $this->prefix, $function->name, $e->getMessage());
                $num_skipped++;
            }
        }

        if ($this->overrides->have_extra_methods($this->prefix)) {
            foreach ($this->overrides->get_extra_methods($this->prefix) as $func_name => $func_body) {
                $this->write_override($func_body, $this->prefix, $func_name);
                $func_defs[] = sprintf(Templates::method_entry,
                                       $this->prefix,
                                       $func_name, 'NULL', 'ZEND_ACC_PUBLIC|ZEND_ACC_STATIC');
                $this->divert("gen", "%%%%  %-11s %s::%s\n", "function", $this->prefix, $func_name);
                $num_written++;
            }
        }

        sort($func_defs);
        if ($func_defs) {
            $this->fp->write(sprintf(Templates::functions_decl, strtolower($this->lprefix)));
            $this->fp->write(join('', $func_defs));
            $this->fp->write(Templates::functions_decl_end);
        }

        $this->log_print("(%d written, %d skipped)\n", $num_written, $num_skipped);
        return $func_defs;
    }

    function write_prop_lists()
    {
        global  $class_prop_list_header,
                $class_prop_list_footer;
        
        $this->fp->write("\n");
        foreach ($this->parser->objects as $object) {
            if (count($object->fields) == 0) continue;

            $this->fp->write(sprintf($class_prop_list_header,
                                strtolower($object->in_module) . '_' .
                                strtolower($object->name)));
            foreach ($object->fields as $field_def) {
                list(, $field_name) = $field_def;
                $this->fp->write("\t\"$field_name\",\n");
            }
            $this->fp->write($class_prop_list_footer);
        }
    }

    function write_class_entries()
    {
        $this->fp->write("\n");
        foreach ($this->parser->objects as $object) {
            $this->fp->write(sprintf(Templates::class_entry, $object->ce));
        }
        foreach ($this->parser->boxed as $object) {
            $this->fp->write(sprintf(Templates::class_entry, $object->ce));
        }
        if ($this->parser->functions || $this->parser->enums) {
            $this->fp->write(sprintf(Templates::class_entry, $this->lprefix . '_ce'));
        }
    }

    function make_header($string)
    {
        $res = str_repeat('=', 72) . "\n";
        $res .= str_pad($string, 72, " ", STR_PAD_BOTH) . "\n";
        $res .= str_repeat('=', 72) . "\n";

        return $res;
    }

    function write_source($savefile)
    {
        $this->not_generated_list = array();;
        $this->log_print($this->make_header("Summary"));

        $this->fp = new LineOutput(fopen($savefile, 'w'), $savefile);
        $this->fp->write("#include \"php_gtk.h\"");
        $this->fp->write("\n#if HAVE_PHP_GTK\n");
        $this->fp->write($this->overrides->get_headers());
        $this->write_class_entries();
        $this->write_constants();
        $this->write_classes();
        $this->fp->write("\n#endif /* HAVE_PHP_GTK */\n");

        $this->log("\n\n");
        $this->log($this->make_header("Generated Items"));
        $this->log("%%%% - overriden\n\n");
        $this->log("%s", $this->diversions["gen"]);

        $this->log("\n\n");
        $this->log($this->make_header("Not Generated Items"));
        $this->log("%s", $this->diversions["notgen"]);
    }
}

/* simple fatal_error function 
        - useful in 4.2.0-dev and later as it will actually halt exectution of make
    - needs to output to stderr as the default print would end up inside the code
    - TODO - the make file outputs an empty file to gen_XXX.c, there must be a
      better way  to delete it... - by using output buffering or somthing?
*/  
function fatal_error($message) {
    $fh = fopen("php://stderr", "w");
        fwrite($fh,"\n\n\n\n
========================================================================    
    There was a Serious error with the PHP-GTK generator script
========================================================================    
    $message
========================================================================    
    You should type this to ensure that the c source is correctly
    generated before attempting to make again.
    
    #find . | grep defs | xargs touch

    \n\n\n\n");
    fclose($fh);
    exit(1);
}   


$old_error_reporting = error_reporting(E_ALL);

if (!isset($_SERVER['argv'])) 
    fatal_error("
        Could not read command line arguments for generator.php 
        Please ensure that this option is set in your php.ini
        register_argc_argv = On     
    ");


if (isset($_SERVER['argc']) &&
    isset($_SERVER['argv'])) {
    $argc = $_SERVER['argc'];
    $argv = $_SERVER['argv'];
}

/* An ugly hack to counteract PHP's pernicious desire to treat + as an argument
   separator in command-line version. */
array_walk($argv, create_function('&$x', '$x = urldecode($x);'));

    
$result = Console_Getopt::getopt($argv, 'l:o:p:c:r:f:');
if (!$result || count($result[1]) < 2)
    die("usage: php generator.php [-l logfile] [-o overridesfile] [-p prefix] [-c functionclass ] [-r typesfile] [-f savefile] defsfile\n");

list($opts, $argv) = $result;

$prefix = 'Gtk';
$function_class = null;
$overrides = new Overrides();
$register_defs = array();
$savefile = 'php://stdout';
$logfile = 'php://stderr';

foreach ($opts as $opt) {
    list($opt_spec, $opt_arg) = $opt;
    if ($opt_spec == 'o') {
        $overrides = new Overrides($opt_arg);
    } else if ($opt_spec == 'p') {
        $prefix = $opt_arg;
    } else if ($opt_spec == 'c') {
        $function_class = $opt_arg;
    } else if ($opt_spec == 'r') {
        $register_defs[] = $opt_arg;
    } else if ($opt_spec == 'f') {
        $savefile = $opt_arg;
    } else if ($opt_spec == 'l') {
        $logfile = $opt_arg;
    }
}

if (file_exists(dirname($argv[1]) . '/arg_types.php')) {
    include(dirname($argv[1]) . '/arg_types.php');
}

$parser = new Defs_Parser($argv[1]);
$generator = new Generator($parser, $overrides, $prefix, $function_class);
$generator->set_logfile($logfile);
foreach ($register_defs as $defs) {
    $type_parser = new Defs_Parser($defs);
    $type_parser->start_parsing();
    $generator->register_types($type_parser);
}
$parser->start_parsing();
$generator->register_types();
$generator->write_source($savefile);

error_reporting($old_error_reporting);

/* vim: set et sts=4: */
?>
