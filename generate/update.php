<?php
	echo '<pre>';
	
	set_time_limit( 300 );
	
	$IncludeGLOB = __DIR__ . '/include/*.inc';
	
	/**
	 * @section Parse files and construct arrays of functions and constants
	 */
	
	$IncludeList = glob( $IncludeGLOB );
	
	$BigListOfFunctions = Array();
	$BigListOfConstants = Array();
	$FilesList = Array();
	
	foreach( $IncludeList as $FilePath )
	{
		$File = file_get_contents( $FilePath );
		
		$FileName = str_replace( '.inc', '', basename( $FilePath ) );
		
		$FilesList[ $FileName ] = $FilePath;
		
		if( empty( $File ) )
		{
			continue;
		}
		
		// Normalize line endings
		$File = str_replace( "\r\n", "\n", $File );
		$File = str_replace( "\r", "\n", $File );
		
		$BeginsWithComment = substr( $File, 0, 2 ) === '/*';
		
		$File = explode( "\n", $File );
		
		$Lines = Count( $File );
		$Count = 0;
		
		$InSection = false;
		$OpenComment = false;
		$FunctionUntilNextCommentBlock = false;
		$CommentBlock = Array();
		$FunctionBuffer = Array();
		
		$Functions = Array();
		$Constants = Array();
		
		foreach( $File as $Line )
		{
			++$Count;
			
			/*if( trim( $Line ) === '' && $Count < $Lines )
			{
				continue;
			}*/
			
			$Beginning = substr( $Line, 0, 2 );
			
			if( $Beginning === '//' )
			{
				//throw new Exception( 'Invalid comment type: ' . $Line );
			}
			
			$IsCommentOpening = $Beginning === '/*';
			$IsFunction = preg_match( '/^(stock|functag|native|forward)(?!\s*const)/', $Line ) === 1;
			
			if( $FunctionUntilNextCommentBlock )
			{
				if( $IsFunction || $IsCommentOpening || $Count === $Lines )
				{
					$Comment = implode( "\n", $CommentBlock );
					$Comment = ParseCommentBlock( $Comment );
					$Comment = SplitCommentBlock( $Comment );
					
					if( substr( $Comment[ 0 ], 0, 8 ) === '@section' )
					{
						$InSection = true;
						
						$Comment[ 0 ] = substr( $Comment[ 0 ], 8 );
					}
					else if( $InSection && substr( $Comment[ 0 ], 0, 11 ) === '@endsection' )
					{
						$InSection = false;
					}
					
					$Function = Array(
						'Comment' => Trim( $Comment[ 0 ] ),
						'CommentTags' => ParseTags( $Comment[ 1 ] )
					);
					
					if( $IsFunction )
					{
						$FunctionBuffer[ ] = $Line;
						
						$Function[ 'FunctionName' ] = GetFunctionName( $Line );
						$Function[ 'Function' ] = trim( implode( "\n", $FunctionBuffer ) );
						
						$Functions[ ] = $Function;
					}
					else
					{
						$Function[ 'Constant' ] = trim( implode( "\n", $FunctionBuffer ) );
						
						$Constants[ ] = $Function;
					}
					
					$CommentBlock = Array();
					$FunctionBuffer = Array();
					
					$FunctionUntilNextCommentBlock = false;
				}
				else
				{
					$FunctionBuffer[ ] = $Line;
				}
			}
			else if( !$IsCommentOpening && $IsFunction )
			{
				$Functions[ ] = Array(
					'Comment' => 'This function has no description.',
					'CommentTags' => Array(),
					'Function' => trim( $Line ),
					'FunctionName' => GetFunctionName( $Line )
				);
			}
			
			if( $IsCommentOpening )
			{
				if( $OpenComment )
				{
					throw new Exception( 'Found a comment opening while having a comment open already: ' . $Line );
				}
				
				$OpenComment = true;
			}
			
			if( $OpenComment )
			{
				$CommentBlock[ ] = $Line;
				
				if( substr( rtrim( $Line ), -2 ) === '*/' )
				{
					$OpenComment = false;
					$FunctionUntilNextCommentBlock = true;
				}
			}
		}
		
		// Skip first comment
		if( $BeginsWithComment && !empty( $Constants ) )
		{
			array_shift( $Constants );
		}
		
		$BigListOfFunctions[ $FileName ] = $Functions;
		$BigListOfConstants[ $FileName ] = $Constants;
	}
	
	unset( $Functions, $Constants, $Line, $File, $CommentBlock, $FunctionBuffer, $IncludeList );
	
	/**
	 * @endsection
	 */
	
	/**
	 * @section Functions used for parsing comment blocks and stuff
	 *
	 * @note Some of the functions were shamelessly copied from https://github.com/phpDocumentor/ReflectionDocBlock/
	 */
	
	function ParseCommentBlock( $Comment )
	{
		$Comment = trim(
			preg_replace(
				'#[ \t]*(?:\/\*\*|\*\/|\*)?[ \t]{0,1}(.*)?#u',
				'$1',
				$Comment
			)
		);
		
		if( substr( $Comment, -2 ) === '*/' )
		{
			$Comment = trim( substr( $Comment, 0, -2 ) );
		}
		
		if( substr( $Comment, 0, 2 ) === '/*' )
		{
			$Comment = trim( substr( $Comment, 2 ) );
		}
		
		return $Comment;
	}
	
	function SplitCommentBlock( $Comment )
	{
		if( strpos( $Comment, '@' ) === 0 )
		{
			$matches = array( $Comment, '' );
		}
		else
		{
			// clears all extra horizontal whitespace from the line endings to prevent parsing issues
			$Comment = preg_replace( '/\h*$/Sum', '', $Comment );
			
			preg_match(
				'/
				\A (?:
				\s* # first seperator (actually newlines but it\'s all whitespace)
				(?! @\pL ) # disallow the rest, to make sure this one doesn\'t match,
				#if it doesn\'t exist
				(
				[^\n]+
				(?: \n+
				(?! [ \t]* @\pL ) # disallow second seperator (@param)
				[^\n]+
				)*
				)
				)?
				(\s+ [\s\S]*)? # everything that follows
				/ux',
				$Comment,
				$matches
			);
			
			array_shift( $matches );
			
			while( count( $matches ) < 2 )
			{
				$matches[] = '';
			}
		}
		
		return $matches;
	}
	
	function ParseTags( $tags )
	{
		$result = array();
		$tags = trim( $tags );
		
		if( $tags !== '' )
		{
			if( $tags[ 0 ] !== '@' )
			{
				throw new Exception( 'A tag block started with text instead of an actual tag, this makes the tag block invalid: ' . $tags );
			}
			
			foreach( explode( "\n", $tags ) as $tag_line )
			{
				if( isset( $tag_line[ 0 ] ) && $tag_line[ 0 ] === '@' )
				{
					$result[] = $tag_line;
				}
				else
				{
					$result[ count( $result ) - 1 ] .= "\n" . $tag_line;
				}
			}
			
			foreach( $result as $key => $tag_line )
			{
				if( preg_match( '/^@([\w\-\_\\\\]+)(?:\s*([^\s].*)|$)?/us', trim( $tag_line ), $Matches ) !== 1 )
				{
					throw new Exception( 'Invalid tag_line detected: ' . $tag_line );
				}
				
				$result[ $key ] = ParseTag( $Matches );
			}
		}
		
		return $result;
	}
	
	function ParseTag( $Matches )
	{
		$Tag = $Matches[ 1 ];
		$Line = isset( $Matches[ 2 ] ) ? $Matches[ 2 ] : '';
		
		$Return = Array(
			'Tag' => $Tag
		);
		
		// https://github.com/phpDocumentor/ReflectionDocBlock/tree/master/src/phpDocumentor/Reflection/DocBlock/Tag
		switch( $Tag )
		{
			case 'param':
			{
				$Parts = preg_split( '/(\s+)/Su', $Line, 2 );
				
				if( isset( $Parts[ 0 ] ) )
				{
					$Return[ 'Variable' ] = $Parts[ 0 ];
				}
				
				if( isset( $Parts[ 1 ] ) )
				{
					$Return[ 'Description' ] = $Parts[ 1 ];
				}
				
				break;
			}
			case 'noreturn':
			{
				if( !empty( $Line ) )
				{
					throw new Exception( '@noreturn must not contain any text: ' . $Line );
				}
				
				break;
			}
			case 'extra':
			{
				$Return[ 'Tag' ] = 'param';
				$Return[ 'Variable' ] = '...';
				$Return[ 'Description' ] = $Line;
				
				break;
			}
			case 'return':
			{
				if( empty( $Line ) )
				{
					throw new Exception( '@return can not be empty: ' . $Line );
				}
			}
			default:
			{
				$Return[ 'Description' ] = $Line;
			}
		}
		
		return $Return;
	}
	
	function GetFunctionName( $Line )
	{
		$Line = substr( $Line, 0, strpos( $Line, '(' ) );
		$Line = trim( $Line );
		
		$PositionStart = strrpos( $Line, ':' );
		
		if( $PositionStart === false )
		{
			$PositionStart = strrpos( $Line, ' ' );
		}
		
		$FunctionName = substr( $Line, $PositionStart + 1 );
		$FunctionType = substr( $Line, 0, strpos( $Line, ' ' ) );
		
		return Array(
			trim( $FunctionName ),
			trim( $FunctionType )
		);
	}
	
	/**
	 * @endsection
	 */
	
	/**
	 * @section Insert everything into the database
	 */
	
	require __DIR__ . '/../settings.php';
	
	$StatementInsertFile = $Database->prepare( 'INSERT INTO `' . $Columns[ 'Files' ] . '` (`IncludeName`, `Content`) VALUES (?, ?) '
	                                         . 'ON DUPLICATE KEY UPDATE `Content` = ?' );
	
	$StatementInsertFunction = $Database->prepare( 'INSERT INTO `' . $Columns[ 'Functions' ] . '` (`Function`, `FullFunction`, `Type`, `Comment`, `Tags`, `IncludeName`) VALUES (?, ?, ?, ?, ?, ?) '
	                                             . 'ON DUPLICATE KEY UPDATE `FullFunction` = ?, `Type` = ?, `Comment` = ?, `Tags` = ?, `IncludeName` = ?' );
	
	$StatementInsertConstant = $Database->prepare( 'INSERT INTO `' . $Columns[ 'Constants' ] . '` (`Constant`, `Comment`, `Tags`, `IncludeName`) VALUES (?, ?, ?, ?)' );
	
	try
	{
		$Database->beginTransaction();
		
		foreach( $BigListOfFunctions as $IncludeName => $Functions )
		{
			$File = file_get_contents( $FilesList[ $IncludeName ] );
			
			$StatementInsertFile->execute(
				Array(
					$IncludeName,
					$File,
					$File
				)
			);
			
			foreach( $Functions as $Function )
			{
				$Tags = json_encode( $Function[ 'CommentTags' ] );
				
				$StatementInsertFunction->execute(
					Array(
						$Function[ 'FunctionName' ][ 0 ],
						$Function[ 'Function' ],
						$Function[ 'FunctionName' ][ 1 ],
						$Function[ 'Comment' ],
						$Tags, 
						$IncludeName,
						
						$Function[ 'Function' ],
						$Function[ 'FunctionName' ][ 1 ],
						$Function[ 'Comment' ],
						$Tags,
						$IncludeName
					)	
				);
			}
		}
		
		$Database->commit();
		$Database->beginTransaction();
		
		// Not really nice way of doing things
		$Database->query( 'TRUNCATE TABLE `' . $Columns[ 'Constants' ] . '`' );
		
		foreach( $BigListOfConstants as $IncludeName => $Functions )
		{
			foreach( $Functions as $Function )
			{
				$Tags = json_encode( $Function[ 'CommentTags' ] );
				
				$StatementInsertConstant->execute(
					Array(
						$Function[ 'Constant' ],
						$Function[ 'Comment' ],
						$Tags, 
						$IncludeName
					)	
				);
			}
		}
		
		$Database->commit();
	}
	catch( PDOException $e )
	{
		$Database->rollback();
		
		throw new Exception( 'Caught PDOException: ' . $e->getMessage() );
	}
	
	echo 'OK' . PHP_EOL;
