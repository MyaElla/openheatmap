#!/usr/bin/php
<?php

/*
OpenHeatMap processing
Copyright (C) 2010 Pete Warden <pete@petewarden.com>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once('cliargs.php');
require_once('osmways.php');

ini_set('memory_limit', '-1');

define('BIGGEST_AREA', 999999999);

function has_hit_target($vertex_count, $vertex_target, $total_area_error, $area_target)
{
    if ($vertex_count>0)
        $hit_target = ($vertex_count<=$vertex_target);
    else
        $hit_target = ($total_area_error>=$area_target);
        
    return $hit_target;
}

function calculate_node_usage(&$nodes, &$ways)
{
    foreach ($nodes as $node_id => &$node_data)
    {
        $node_data['used_by'] = array();
    }

    foreach ($ways as $way_id => &$way_data)
    {
        $way_data['original_nds_count'] = count($way_data['nds']);
    
        foreach ($way_data['nds'] as $nd_index => $nd_ref)
        {
            $nodes[$nd_ref]['used_by'][] = array(
                'way_id' =>$way_id,
                'nd_index' => $nd_index,
            );

            $nodes[$nd_ref]['area_error'] = 0;
            
//            if ($nd_ref==1768)
//            {
//                error_log(print_r($node_data, true));
//            }
            
        }
        
//        if ($way_id==1746)
//        {
//            error_log('Found '.print_r($way_data, true));
//        }
    }

}

function sort_nodes_by_area(&$nodes, &$ways)
{
    foreach ($nodes as $node_id => &$node_data)
    {
        $used_by = $node_data['used_by'];
        
        $way_id = $used_by[0]['way_id'];
        $way = $ways[$way_id];
        $nds = $way['nds'];
        
        if ((count($used_by)>2)||(count($nds)<=3))
        {
            $area = BIGGEST_AREA;
        }
        else
        {
            $nd_index = $used_by[0]['nd_index'];
                        
            $nds_count = $way['original_nds_count'];
            
            $previous_index = ($nd_index-1);
            while (true)
            {
                $previous_index = (($previous_index+$nds_count)%$nds_count);
                if (isset($nds[$previous_index]))
                    break;
                $previous_index -= 1;
                if ($previous_index===($nd_index-1))
                    break;
            }

            $next_index = ($nd_index+1);
            while (true)
            {
                $next_index = (($next_index+$nds_count)%$nds_count);
                if (isset($nds[$next_index]))
                    break;
                $next_index += 1;
                if ($next_index===($nd_index+1))
                    break;
            }

            if (!isset($nds[$previous_index])||
                !isset($nds[$next_index]))
            {
                $area = 0;
            }
            else
            {
                $previous_ref = $nds[$previous_index];
                $next_ref = $nds[$next_index];
            
                if (!isset($nodes[$previous_ref]))
                {
                    error_log('Bad node '.$previous_ref.' found in '.$way_id);
                    die();
                }
            
                $previous_data = $nodes[$previous_ref];
                $next_data = $nodes[$next_ref];
                
                $current_lat = $node_data['lat'];
                $current_lon = $node_data['lon'];
                
                $previous_lat = $previous_data['lat'];
                $previous_lon = $previous_data['lon'];
                
                $next_lat = $next_data['lat'];
                $next_lon = $next_data['lon'];
                
                $previous_lat_delta = ($current_lat-$previous_lat);
                $previous_lon_delta = ($current_lon-$previous_lon);
                
                $next_lat_delta = ($next_lat-$current_lat);
                $next_lon_delta = ($next_lon-$current_lon);

                $cross = 
                    ($next_lon_delta*$previous_lat_delta)-
                    ($next_lat_delta*$previous_lon_delta);
                
                $area = abs($cross);
            }

        }
        
        $area_error = $node_data['area_error'];
        
        $node_data['area'] = ($area+$area_error);
    }
    
    $sortfunction = create_function('$a, $b', 'if ($a["area"]>$b["area"]) return 1; else return -1;'); 
    uasort($nodes, $sortfunction);
}

function decimate_ways($input_osm_ways, $decimate)
{
    if ($decimate==0)
        return $input_osm_ways;

    $frequency = (100.0/$decimate);

    $result = new OSMWays();

    $input_ways = $input_osm_ways->ways;
    $input_nodes = $input_osm_ways->nodes;
    
    foreach ($input_ways as $way)
    {
        $result->begin_way();
     
        foreach ($way['tags'] as $key => $value)
        {
            $result->add_tag($key, $value);
        }

        $nds_count = count($way['nds']);
        $nd_index = 0;
        foreach ($way['nds'] as $nd_ref)
        {
            $is_first = ($nd_index==0);
            $is_last = ($nd_index==($nds_count-1));
 
            $nd_index += 1;
                                  
            if (!isset($input_nodes[$nd_ref]))
                continue;
            
            $mod = fmod($nd_index, $frequency);
            $is_keeper = ($mod<0.998);
            
            $use_vertex = ($is_first||$is_last||$is_keeper);
            
            if ($use_vertex)
            {
                $node = $input_nodes[$nd_ref];
                $result->add_vertex($node['lat'], $node['lon']);
            }
        }
        
        $result->end_way();
    }
    
    return $result;
}

function reduce_lod(&$osm_ways, $vertex_target, $area_target, $area_transfer, $decimate)
{
    if ($decimate>0)
    {
        $osm_ways = decimate_ways($osm_ways, $decimate);
    }

    $nodes = &$osm_ways->nodes;
    $ways = &$osm_ways->ways;

    calculate_node_usage($nodes, $ways);
    
    $vertex_count = count($nodes);
    $total_area_error = 0;
    
    $hit_essential_nodes = false;
    
    while (true)
    {
        $hit_target = has_hit_target($vertex_count, $vertex_target, $total_area_error, $area_target);
        
        error_log('vertex_count: '.$vertex_count);
                    
        if ($hit_target||$hit_essential_nodes)
            break;

        sort_nodes_by_area($nodes, $ways);
        
        $nodes_removed = 0;
        
        foreach ($nodes as $node_id => &$node_data)
        {
//            error_log('Foo: '.print_r($nodes['1768'], true));

            $area = $node_data['area'];
            
            if ($area>=BIGGEST_AREA)
            {
                $hit_essential_nodes = true;
                break;
            }
            
//            error_log('Removing '.$node_id.' with area '.$area);
            
            $vertex_count -= 1;
            $total_area_error += $area;
                    
            $used_by = $node_data['used_by'];
            
            if ($vertex_target>0)
                $nodes_per_pass = max(100, ceil(($vertex_count-$vertex_target)/10));
            else
                $nodes_per_pass = 100;

            $is_essential = false;
            foreach ($used_by as $used_by_index => $used_by_entry)
            {
                $way_id = $used_by_entry['way_id'];
                $way = &$ways[$way_id];
                $nds = &$way['nds'];
                
                // Don't remove more points from a way that's down to just 3
                if (count($nds)<=3)
                {
                   $is_essential = true;
                    break;
                }
            }

            if ($is_essential)
                continue;

            foreach ($used_by as $used_by_index => $used_by_entry)
            {
                $way_id = $used_by_entry['way_id'];
                $nd_index = $used_by_entry['nd_index'];
                
                $way = &$ways[$way_id];
                $nds = &$way['nds'];
                
                // Don't remove more points from a way that's down to just 3
                if (count($nds)<=3)
                   continue;
                                                                                                                             
                if ($used_by_index===0)
                {                                
//                    $way_string = '';
//                    foreach ($nds as $i => $r)
//                        $way_string .= $i.':'.$r.',';
//                    error_log("Vertices: ".$way_string);

                    $nds_count = $way['original_nds_count'];

                    $previous_index = ($nd_index-1);
                    while (true)
                    {
                        $previous_index = (($previous_index+$nds_count)%$nds_count);
                        if (isset($nds[$previous_index]))
                            break;
                        $previous_index -= 1;
                        if ($previous_index===($nd_index-1))
                            break;
                    }

                    $next_index = ($nd_index+1);
                    while (true)
                    {
                        $next_index = (($next_index+$nds_count)%$nds_count);
                        if (isset($nds[$next_index]))
                            break;
                        $next_index += 1;
                        if ($next_index===($nd_index+1))
                            break;
                    }
                    
                    if (isset($nds[$previous_index])&&
                        isset($nds[$next_index]))
                    {
                        $previous_ref = $nds[$previous_index];
                        $next_ref = $nds[$next_index];
                    
                        $nodes[$previous_ref]['area_error'] += ($area*$area_transfer);
                        $nodes[$next_ref]['area_error'] += ($area*$area_transfer);
                    }
                }

                unset($nds[$nd_index]);
            }
                
            unset($nodes[$node_id]);
            $nodes_removed += 1;

            $hit_target = has_hit_target($vertex_count, $vertex_target, $total_area_error, $area_target);
                
            if ($hit_target || ($nodes_removed>=$nodes_per_pass))
                break;
        }
    }
}

function reclose_ways(&$osm_ways, $force_closed)
{
    $ways = &$osm_ways->ways;

    foreach ($ways as &$way)
    {
        if ($way['is_closed']||$force_closed)
        {
            $way['nds'] = array_values($way['nds']);
            
            $nds_count = count($way['nds']);
            if ($nds_count===0)
                continue;

            if ($way['nds'][0]!==$way['nds'][$nds_count-1])
                $way['nds'][] = $way['nds'][0];
                
            $way['original_nds_count'] = count($way['nds']);
        }
    }

}

$cliargs = array(
	'vertextarget' => array(
		'short' => 'v',
		'type' => 'optional',
		'description' => 'If set, the target number of vertices to reduce the map to',
        'default' => 0,
	),
	'areatarget' => array(
		'short' => 'a',
		'type' => 'optional',
		'description' => 'If set, the maximum acceptable error in degrees-squared for a way before reduction is stopped',
        'default' => 0,
	),
	'inputfile' => array(
		'short' => 'i',
		'type' => 'optional',
		'description' => 'The file to read the input OSM XML data from - if unset, will read from stdin',
        'default' => 'php://stdout',
	),
	'outputfile' => array(
		'short' => 'o',
		'type' => 'optional',
		'description' => 'The file to write the output OSM XML data to - if unset, will write to stdout',
        'default' => 'php://stdout',
	),
	'forceclosed' => array(
		'short' => 'c',
		'type' => 'switch',
		'description' => 'If set, any open ways will be manually closed',
	),
	'areatransfer' => array(
		'short' => 't',
		'type' => 'optional',
		'description' => 'How much of a bias to use towards detailed areas',
        'default' => '0.25',
	),
    'decimate' => array(
        'short' => 'd',
        'type' => 'optional',
        'description' => 'Percentage of vertices to arbitrarily remove before in-depth processing',
        'default' => '0',
    ),
);	

$options = cliargs_get_options($cliargs);

$vertex_target = $options['vertextarget'];
$area_target = $options['areatarget'];
$input_file = $options['inputfile'];
$output_file = $options['outputfile'];
$force_closed = $options['forceclosed'];
$area_transfer = $options['areatransfer'];
$decimate = $options['decimate'];

if (($vertex_target===0)&&($area_target===0))
{
    print 'You must specify either -v/--vertextarget or -a/--areatarget'."\n";
    cliargs_print_usage_and_exit($cliargs);
}

$osm_ways = new OSMWays();
$input_contents = file_get_contents($input_file) or die("Couldn't read file '$input_file'");
$osm_ways->deserialize_from_xml($input_contents);

reduce_lod($osm_ways, $vertex_target, $area_target, $area_transfer, $decimate);
reclose_ways($osm_ways, $force_closed);

$output_contents = $osm_ways->serialize_to_xml();
file_put_contents($output_file, $output_contents) or die("Couldn't write file '$output_file'");

?>