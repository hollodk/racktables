<?php

define ('CACHE_DURATION', 604800); // 7 * 24 * 3600

function checkIMSCondition()
{
	if
	(
		! array_key_exists ('HTTP_IF_MODIFIED_SINCE', $_SERVER)
		or ! array_key_exists ('img', $_REQUEST)
		or $_REQUEST['img'] != 'progressbar'
	)
		return;
	$client_time = HTTPDateToUnixTime ($_SERVER['HTTP_IF_MODIFIED_SINCE']);
	if ($client_time !== FALSE && $client_time !== -1) // readable
	{
		$server_time = time();
		// not in future and not yet expired
		if ($client_time <= $server_time && $client_time + CACHE_DURATION >= $server_time)
		{
			header ('Last-Modified: ' . $_SERVER['HTTP_IF_MODIFIED_SINCE'], TRUE, 304);
			exit;
		}
	}
}

function dispatchImageRequest()
{
	genericAssertion ('img', 'string');
	global $pageno, $tabno;
	switch ($_REQUEST['img'])
	{
	case 'minirack': // rack security context
		$pageno = 'rack';
		$tabno = 'default';
		fixContext();
		if (!permitted())
			renderAccessDeniedImage();
		else
			renderRackThumb (getBypassValue());
		break;
	case 'progressbar': // no security context
		assertUIntArg ('done', TRUE);
		// 'progressbar's never change, make browser cache the result
		header ('Cache-Control: private, max-age=' . CACHE_DURATION . ', pre-check=' . CACHE_DURATION);
		header ('Last-Modified: ' . gmdate (DATE_RFC1123));
		renderProgressBarImage ($_REQUEST['done']);
		break;
	case 'preview': // file security context
		$pageno = 'file';
		$tabno = 'download';
		fixContext();
		if (!permitted())
			renderAccessDeniedImage();
		else
			renderFilePreview (getBypassValue());
		break;
	default:
		renderErrorImage();
	}
}

//------------------------------------------------------------------------
function HTTPDateToUnixTime ($string)
{
	//Written per RFC 2616 3.3.1 - Full Date
	//http://www.w3.org/Protocols/rfc2616/rfc2616-sec3.html
	$month_number = array
	(
		'Jan' => 1,
		'Feb' => 2,
		'Mar' => 3,
		'Apr' => 4,
		'May' => 5,
		'Jun' => 6,
		'Jul' => 7,
		'Aug' => 8,
		'Sep' => 9,
		'Oct' => 10,
		'Nov' => 11,
		'Dec' => 12,
	);

	$formats = array();
	# RFC2616 dictates exchanged timestamps to be in GMT TZ, and RFC822
	# (which RFC1123 relies on) explicitly defines, that "GMT" is equivalent
	# to "-0000" and "+0000".
	$formats['rfc1123'] = '/^(Sun|Mon|Tue|Wed|Thu|Fri|Sat), (\d{2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{4}) (\d{2}):(\d{2}):(\d{2}) (?:GMT|[-+]0000)$/';
	$formats['rfc850'] = '/^(Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday), (\d{2})-(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-(\d{2}) (\d{2}):(\d{2}):(\d{2}) (?:GMT|[-+]0000)$/';
	$formats['asctime'] = '/^(Sun|Mon|Tue|Wed|Thu|Fri|Sat) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{2}|\d{1}) (\d{2}):(\d{2}):(\d{2}) (\d{4})$/';

	$matches = array();
	if (preg_match ($formats['rfc1123'], $string, $matches)) {
		$hours = $matches[5];
		$minutes = $matches[6];
		$seconds = $matches[7];
		$month = $month_number[$matches[3]];
		$day = $matches[2];
		$year = $matches[4];
	} elseif (preg_match ($formats['rfc850'], $string, $matches)) {
		$hours = $matches[5];
		$minutes = $matches[6];
		$seconds = $matches[7];
		$month = $month_number[substr($matches[3],0,3)];
		$day = $matches[2];
		$year = $matches[4];
	} elseif (preg_match ($formats['asctime'], $string, $matches)) {
		$hours = $matches[4];
		$minutes = $matches[5];
		$seconds = $matches[6];
		$month = $month_number[$matches[2]];
		$day = $matches[3];
		$year = $matches[7];
	} else
		return false;
	return gmmktime ($hours, $minutes, $seconds, $month, $day, $year);
}

function renderErrorImage ()
{
	header("Content-type: image/png");
	// "ERROR", 76x17, red on white
	echo base64_decode
	(
		'iVBORw0KGgoAAAANSUhEUgAAAEwAAAARCAYAAAB3h0oCAAAAAXNSR0IArs4c6QAAALBJREFUWMPt' .
		'WFsOwCAIG4v3vzL7WEyWxQdVwM1A4l/F2iHVETPzESGOMyTAInURRP0suUhb2FIho/jWXO38w4KN' .
		'LPDGEt2jlgPBZxFKc2o8UT7Lj6SkAmfw1nx+28MkVWQlcjT9EOwjLqnpaNImi+I1j/YSl5RY/gx+' .
		'VCCF/MnkCz4JZQtvEUXx1nyW9jCUlPVLbTJ/3MO2dsnWRq2Nwl2wTarM51rhsVEnDhT/w7C4APaJ' .
		'ZhkIGYaUAAAAAElFTkSuQmCC'
	);
}

// Having a local caching array speeds things up. A little.
function colorFromHex ($image, $hex)
{
	static $colorcache = array ();
	if (isset ($colorcache[$hex]))
		return $colorcache[$hex];
	$r = hexdec ('0x' . substr ($hex, 0, 2));
	$g = hexdec ('0x' . substr ($hex, 2, 2));
	$b = hexdec ('0x' . substr ($hex, 4, 2));
	$c = imagecolorallocate ($image, $r, $g, $b);
	$colorcache[$hex] = $c;
	return $c;
}

function renderRackThumb ($rack_id = 0)
{
	// Don't call DB extra times, hence we are most probably not the
	// only script wishing to access the same data now.
	if (NULL !== ($thumbcache = loadThumbCache ($rack_id)))
	{
		header("Content-type: image/png");
		echo $thumbcache;
		return;
	}
	ob_start();
	if (FALSE !== generateMiniRack ($rack_id))
	{
		$capture = ob_get_clean();
		header("Content-type: image/png");
		echo $capture;
		usePreparedUpdateBlade ('Rack', array ('thumb_data' => base64_encode ($capture)), array ('id' => $rack_id));
		return;
	}
	// error text in the buffer
	ob_end_flush();
}

// Output a binary string containing the PNG minirack. Indicate error with return code.
function generateMiniRack ($rack_id)
{
	if (NULL === ($rackData = spotEntity ('rack', $rack_id)))
		return FALSE;
	amplifyCell ($rackData);
	markupObjectProblems ($rackData);
	global $rtwidth;
	$rtdepth = 9;
	$offset[0] = 3;
	$offset[1] = 3 + $rtwidth[0];
	$offset[2] = 3 + $rtwidth[0] + $rtwidth[1];
	$totalheight = 3 + 3 + $rackData['height'] * 2;
	$totalwidth = $offset[2] + $rtwidth[2] + 3;
	$img = @imagecreatetruecolor ($totalwidth, $totalheight)
		or die("Cannot Initialize new GD image stream");
	// cache our palette as well
	$color = array();
	foreach (array ('F', 'A', 'U', 'T', 'Th', 'Tw', 'Thw') as $statecode)
		$color[$statecode] = colorFromHex ($img, getConfigVar ('color_' . $statecode));
	$color['black'] = colorFromHex ($img, '000000');
	$color['gray'] = colorFromHex ($img, 'c0c0c0');
	imagerectangle ($img, 0, 0, $totalwidth - 1, $totalheight - 1, $color['black']);
	imagerectangle ($img, 1, 1, $totalwidth - 2, $totalheight - 2, $color['gray']);
	imagerectangle ($img, 2, 2, $totalwidth - 3, $totalheight - 3, $color['black']);
	for ($unit_no = 1; $unit_no <= $rackData['height']; $unit_no++)
	{
		for ($locidx = 0; $locidx < 3; $locidx++)
		{
			$colorcode = $rackData[$unit_no][$locidx]['state'];
			if (isset ($rackData[$unit_no][$locidx]['hl']))
				$colorcode = $colorcode . $rackData[$unit_no][$locidx]['hl'];
			imagerectangle
			(
				$img,
				$offset[$locidx],
				3 + ($rackData['height'] - $unit_no) * 2,
				$offset[$locidx] + $rtwidth[$locidx] - 1,
				3 + ($rackData['height'] - $unit_no) * 2 + 1,
				$color[$colorcode]
			);
		}
	}
	imagepng ($img);
	imagedestroy ($img);
	return TRUE;
}

function renderProgressBarImage ($done)
{
	$img = @imagecreatetruecolor (100, 10);
	switch (isset ($_REQUEST['theme']) ? $_REQUEST['theme'] : 'rackspace')
	{
		case 'sparenetwork':
			$color['T'] = colorFromHex ($img, '808080');
			$color['F'] = colorFromHex ($img, 'c0c0c0');
			break;
		case 'rackspace': // teal
		default:
			$color['T'] = colorFromHex ($img, getConfigVar ('color_T'));
			$color['F'] = colorFromHex ($img, getConfigVar ('color_F'));
	}
	imagefilledrectangle ($img, 0, 0, $done, 10, $color['T']);
	imagefilledrectangle ($img, $done, 0, 100, 10, $color['F']);
	for ($x = 20; $x <= 80; $x += 20)
	{
		$cc = $x > $done ? $color['T'] : $color['F'];
		imagesetpixel ($img, $x, 0, $cc);
		imagesetpixel ($img, $x, 1, $cc);
		imagesetpixel ($img, $x, 4, $cc);
		imagesetpixel ($img, $x, 5, $cc);
		imagesetpixel ($img, $x, 8, $cc);
		imagesetpixel ($img, $x, 9, $cc);
	}
	header("Content-type: image/png");
	imagepng ($img);
	imagedestroy ($img);
}

function renderAccessDeniedImage ()
{
	$img = @imagecreatetruecolor (1, 1);
	imagefilledrectangle ($img, 0, 0, 1, 1, colorFromHex ($img, '000000'));
	header("Content-type: image/png");
	imagepng ($img);
	imagedestroy ($img);
	die;
}

function renderFilePreview ($file_id)
{
	if ($image = getFileCache ($file_id)) //Cache Hit
	{
		header("Content-type: image/jpeg"); 
		echo $image;
		return;
	}
	//Cache Miss
	$file = getFile ($file_id);
	$image = imagecreatefromstring ($file['contents']);
	unset ($file);
	$width = imagesx ($image);
	$height = imagesy ($image);
	if ($width > getConfigVar ('PREVIEW_IMAGE_MAXPXS') or $height > getConfigVar ('PREVIEW_IMAGE_MAXPXS'))
	{
		$ratio = getConfigVar ('PREVIEW_IMAGE_MAXPXS') / max ($width, $height);
		$newwidth = $width * $ratio;
		$newheight = $height * $ratio;
		$resampled = imagecreatetruecolor ($newwidth, $newheight);
		imagecopyresampled ($resampled, $image, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
		imagedestroy ($image);
		$image = $resampled;
		unset ($resampled);
	}
	header ('Content-type: image/jpeg');
	ob_start();
	imagejpeg ($image);
	imagedestroy ($image);
	commitAddFileCache ($file_id, ob_get_flush());
}

?>