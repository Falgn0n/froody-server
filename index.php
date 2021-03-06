<?php
/**
 * Froody API
 *
 * @version 1.0.0
 */

// ###########################
// ##### Imports
// ###########################
define('PROOT', __DIR__);
require_once PROOT . '/vendor/autoload.php';  // Autoload for SLIM libs
require_once PROOT . '/app.php';

require_once PROOT . '/model/BlockInfo.php';
require_once PROOT . '/model/FroodyEntry.php';
require_once PROOT . '/model/FroodyUser.php';
require_once PROOT . '/model/ResponseEntryAdd.php';
require_once PROOT . '/model/ResponseOk.php';

require_once PROOT . '/util/FormatUtil.php';
require_once PROOT . '/util/Geohash.php';

// ###########################
// ##   Init Slim application
// ###########################
$app = new Slim\App();

// ###########################
// ##   GET / POST handling
// ###########################
$app->GET('/', function ($request, $response, $args) {
    return writeResponse($response, 'Froody.<br>I can see you. Thats fine.');
});

/**
 * GET blockGetGet
 * Summary:
 * Notes: Entry[ ] ** Get entries contained in block
 * Output-Formats: [application/json]
 */
$app->GET('/block/get', function ($request, $response, $args) {
    $queryParams = $request->getQueryParams();
    $geohash = $queryParams['geohash'];
    $minModDate = $queryParams['minModificationDate'];

    //
    // Validations
    //
    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }
    if (($minModDate = FormatUtil::parseDateTime($minModDate)) === false) {
        return writeResponseOk($response, false);
    }
    $geohash = new Geohash($geohash);
    if (!$geohash->isValidAndHasPrecision(5)) {
        return writeResponseOk($response, false);
    }
    $geohash->setGeohash($geohash->getWithMaxPrecision(6));

    //
    //  Request data from db
    //

    // Prepare for db operation
    $geolike = $geohash->getGeohash() . '%';
    $minModificationDateSQL = FormatUtil::dateTimeToSQLTimestamp($minModDate);

    // Entries of requested block since minModificationDate
    $retEntries = [];

    // Prepare statement
    $stmt = $conn->prepare('
        SELECT entryId, geohash, creationDate, entryType, certificationType, distributionType, modificationDate, wasDeleted
        FROM froody_entry
        WHERE geohash LIKE ?
          AND modificationDate >= ?
          AND NOT (modificationDate = ? AND creationDate = ?)
    ');
    $stmt->bind_param('ssss', $geolike, $minModificationDateSQL, $minModificationDateSQL, $minModificationDateSQL);
    if ($stmt->execute()) {
        $dbRS = new FroodyEntry();
        $stmt->store_result();
        $stmt->bind_result($dbRS->entryId, $dbRS->geohash, $dbRS->creationDate, $dbRS->entryType, $dbRS->certificationType, $dbRS->distributionType, $dbRS->modificationDate, $dbRS->wasDeleted);

        while ($stmt->fetch()) {
            $entry = FroodyEntry::create($dbRS);
            $entry->modificationDate = FormatUtil::sqlTimestampToRFC3339String($entry->modificationDate);
            $entry->creationDate = FormatUtil::sqlTimestampToRFC3339String($entry->creationDate);
            $entry->setExtrasEmpty();
            $entry->wasDeleted = $entry->wasDeleted === 1;
            $retEntries[] = $entry;
        }
    } else {
        return writeResponseOk($response, false);
    }

    return writeResponse($response, $retEntries);
});

/**
 * GET blockInfoGet
 * Summary:
 * Notes: Get informations about of or around block/geohash
 * Output-Formats: [application/json]
 */
$app->GET('/block/info', function ($request, $response, $args) {
    $queryParams = $request->getQueryParams();
    $geohash = $queryParams['geohash'];
    $minModDate = $queryParams['minModificationDate'];

    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }

    // Check geohash
    $geohash = new Geohash($geohash);
    if (!$geohash->isValidAndHasPrecision(5)) {
        return writeResponseOk($response, false);
    }
    $geohash->setGeohash($geohash->getWithMaxPrecision(6));

    // Check datetime (should be RFC3339)
    //$minModDate = "2016-12-28T14:53:17.851Z";    // Example date
    if (($minModDate = FormatUtil::parseDateTime($minModDate)) === false) {
        return writeResponseOk($response, false);
    }

    // Init return array
    $ret = [];

    if ($geohash->hasMinPrecision(6)) {
        // Sub-Block --> Query 1+8 blocks
        $geohashNbrStrs = $geohash->getNeighbours();
        foreach ($geohashNbrStrs as $nbrStr) {
            calcAndAddBlockInfoOfSingleBlockToArray($conn, $nbrStr, $minModDate, $ret);
        }
        calcAndAddBlockInfoOfSingleBlockToArray($conn, $geohash->getGeohash(), $minModDate, $ret);
    } else {
        // Big-Block --> Query 1 big block
        /*$blockInfo = getBlockInfoOfSingleBlock($conn, $geohash->getWithMaxPrecision(5), $minModificationDate);
        if ($blockInfo !== false){
            array_push($ret, $blockInfo);
        }*/
        calcAndAddBlockInfoOfSingleBlockToArray($conn, $geohash->getGeohash(), $minModDate, $ret);
    }

    $conn->close();

    if (empty($ret)) {
        return writeResponseOk($response, false);
    } else {
        return writeResponse($response, $ret);
    }
});


function calcAndAddBlockInfoOfSingleBlockToArray($conn, $geohash, $minModificationDate, &$arr)
{
    $blockInfo = getBlockInfoOfSingleBlock($conn, $geohash, $minModificationDate);
    if ($blockInfo !== false) {
        $arr[] = $blockInfo;
    }
}

/**
 * Get the last modification date of block/geohash
 * requires a established database connection
 * and validated geohash
 */
function getBlockInfoOfSingleBlock($conn, $geohash, $minModificationDate)
{
    $blockInfo = BlockInfo::create($geohash);

    // Prepare for db operation
    $geolike = $geohash . '%';
    $minModificationDateSQL = FormatUtil::dateTimeToSQLTimestamp($minModificationDate);

    // Prepare statement
    $stmt = $conn->prepare('
        SELECT modificationDate
        FROM froody_entry
        WHERE geohash LIKE ?
          AND modificationDate >= ?
        ORDER BY modificationDate DESC LIMIT 1
    ');
    $stmt->bind_param('ss', $geolike, $minModificationDateSQL);

    if (!$stmt->execute()) {
        return null;
    }

    $stmt->bind_result($modDateInDb);
    if ($stmt->fetch()) {
        // There was a newer entry in database
        $blockInfo->hasBlockBeenModified = true;
        $blockInfo->modificationDate = FormatUtil::sqlTimestampToRFC3339String($modDateInDb);
    } else {
        // No updates since minimum date
        $blockInfo->hasBlockBeenModified = false;
        $blockInfo->modificationDate = FormatUtil::dateTimeToRFC3339String($minModificationDate);
    }

    return $blockInfo;
}


/**
 * GET userRegisterGet
 * Summary: User Id
 * Notes: The User Id endpoint returns the Id of the User, which was currently created.
 * Output-Formats: [application/json]
 */
$app->GET('/user/register', function ($request, $response, $args) {
    $queryParams = $request->getQueryParams();

    $user = new FroodyUser();

    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }

    $stmt = $conn->prepare('SELECT userId FROM froody_user WHERE userId=? LIMIT 1');
    $stmt->bind_param('i', $user->userId);
    while (true) {
        $user->userId = getRandomInt64();
        $stmt->execute();
        $stmt->store_result();

        // Break if userId was not in DB
        if ($stmt->num_rows === 0) {
            break;
        }
    }
    $stmt = $conn->prepare('INSERT INTO froody_user (userId, creationDate) VALUES (?, UTC_TIMESTAMP())');
    $stmt->bind_param('i', $user->userId);
    if ($stmt->execute()) {
        $conn->close();

        return $response
            ->withStatus(200)
            ->withHeader('Content-type', 'application/json')
            ->write(json_encode($user));
    }
    $conn->close();

    return writeResponseOk($response, false);
});


/**
 * POST entryAddPost
 * Summary: Add a FroodyEntry with details
 * Notes: The Entry/Add endpoint returns a result, if the transmitted FroodyEntry could be created.
 * Output-Formats: [application/json]
 */
$app->POST('/entry/add', function ($request, $response, $args) {
    $appSettings = getSettings();
    $queryParams = $request->getQueryParams();
    $userId = $queryParams['userId'];
    $geohash = $queryParams['geohash'];
    $entryType = (int) $queryParams['entryType'];
    $distributionType = (int) $queryParams['distributionType'];
    $certificationType = (int) $queryParams['certificationType'];
    $description = FormatUtil::limitStrUTF8($queryParams['description'], $appSettings['entry']['maxlen_description']);
    $contact = FormatUtil::limitStrUTF8($queryParams['contact'], $appSettings['entry']['maxlen_contact']);
    $address = FormatUtil::limitStrUTF8($queryParams['address'], $appSettings['entry']['maxlen_address']);

    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }

    $geohash = new Geohash($geohash);


    if ($entryType >= 0 && $geohash->isValidAndHasPrecision(9) && doesUserExists($conn, $userId)) {
        $managementCode = getRandomInt32();
        $stmt = $conn->prepare('
            INSERT INTO froody_entry
            (`userId`, `geohash`, `entryType`, `certificationType`, `distributionType`, `description`, `contact`,`address`,`managementCode`,`creationDate`,`modificationDate`)
            VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
        ');
        $stmt->bind_param('isiiisssi', $userId, $geohash->getWithMaxPrecision(9), $entryType, $certificationType, $distributionType, $description, $contact, $address, $managementCode);
        if ($stmt->execute()) {
            $ret = new ResponseEntryAdd();
            $ret->entryId = $stmt->insert_id;
            $ret->managementCode = $managementCode;

            $stmt = $conn->prepare('SELECT creationDate FROM froody_entry WHERE entryId=?');
            $stmt->bind_param('i', $ret->entryId);
            if ($stmt->execute()) {
                $stmt->store_result();
                $stmt->bind_result($ret->creationDate);
                $stmt->fetch();
                $conn->close();
                $ret->creationDate = FormatUtil::sqlTimestampToRFC3339String($ret->creationDate);

                return writeResponse($response, $ret);
            }
        }
    }
    $conn->close();

    return writeResponseOk($response, false);
});


/**
 * GET entryByIdGet
 * Summary:
 * Notes: Entry ** Get details of one entry
 * Output-Formats: [application/json]
 */
$app->GET('/entry/byId', function ($request, $response, $args) {
    $queryParams = $request->getQueryParams();
    $entryId = $queryParams['entryId'];
    $ret = new FroodyEntry();

    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }

    $stmt = $conn->prepare('
        SELECT geohash, creationDate, entryType, certificationType, distributionType, description, contact, address, modificationDate, wasDeleted
        FROM froody_entry
        WHERE entryId = ?
        LIMIT 1
    ');

    $stmt->bind_param('d', $entryId);
    if ($stmt->execute() === false) {
        return writeResponseOk($response, false);
    }
    $stmt->store_result();
    $stmt->bind_result($ret->geohash, $ret->creationDate, $ret->entryType, $ret->certificationType, $ret->distributionType, $ret->description, $ret->contact, $ret->address, $ret->modificationDate, $ret->wasDeleted);

    if ($stmt->fetch()) {
        $ret->userId = -1;    // Set value to this field, Client must not know this
        $ret->managementCode = -1;
        $ret->wasDeleted = $ret->wasDeleted === 1;
        $ret->creationDate = FormatUtil::sqlTimestampToRFC3339String($ret->creationDate);
        $ret->modificationDate = FormatUtil::sqlTimestampToRFC3339String($ret->modificationDate);
    } else {
        $ret = new ResponseOk();
        $ret->success = false;
    }
    $conn->close();

    return writeResponse($response, $ret);
});


/**
 * GET entryDeleteGet
 * Summary: Delete an Entry
 * Notes: Delete entry by code, userId and entryId
 * Output-Formats: [application/json]
 */
$app->GET('/entry/delete', function ($request, $response, $args) {
    // Write to database, that the entry was deleted
    // So clients can retrieve it via a delta update
    // Removing entries from the DB itself should be done somewhere else
    $queryParams = $request->getQueryParams();
    $userId = $queryParams['userId'];
    $managementCode = $queryParams['managementCode'];
    $entryId = $queryParams['entryId'];

    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }

    $stmt = $conn->prepare('
        SELECT managementCode
        FROM froody_entry
        WHERE userId=?
          AND managementCode=?
          AND entryId=?
        LIMIT 1
    ');
    $stmt->bind_param('iii', $userId, $managementCode, $entryId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 1) {
        $stmt = $conn->prepare('
            UPDATE froody_entry
            SET wasDeleted=1, modificationDate=UTC_TIMESTAMP()
            WHERE entryId=? LIMIT 1');
        $stmt->bind_param('i', $entryId);
        if ($stmt->execute()) {
            $conn->close();

            return writeResponseOk($response, true);
        }
    }
    $conn->close();

    return writeResponseOk($response, false);
});


/**
 * GET entryPopularEntryTypesGet
 * Summary:
 * Notes: Entry.entryType[ ] ** Get a list of popular entry types on server (includes 1+8 blocks around geohash block)
 * Output-Formats: [application/json]
 */
$app->GET('/entry/popularEntryTypes', function ($request, $response, $args) {
    // COUNT = 510 000 000 / (156*156)
    $queryParams = $request->getQueryParams();
    $geohash = $queryParams['geohash'];

    // NOT IMPLEMENTED
    return writeResponseOk($response, false);
});

/**
 * GET userIsEnabledGet
 * Summary:
 * Notes: Check if user is enabled (by User.userId)
 * Output-Formats: [application/json]
 */
$app->GET('/user/isEnabled', function ($request, $response, $args) {
    $queryParams = $request->getQueryParams();
    $userId = $queryParams['userId'];

    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }
    $isEnabled = doesUserExists($conn, $userId);

    $ret = ResponseOk::create($isEnabled);

    return writeResponse($response, $ret);
});

/**
 * GET adminCleanupGet
 * Summary:
 * Notes: Clean up user and entry database
 * Output-Formats: [application/json]
 */
$app->GET('/admin/cleanup', function ($request, $response, $args) {
    $appSettings = getSettings();
    $queryParams = $request->getQueryParams();
    $adminCode = $queryParams['adminCode'];
    if (empty($appSettings['admin']['adminCode']) || $adminCode !== $appSettings['admin']['adminCode']) {
        return writeResponseOk($response, false);
    }

    if (($conn = getDB()) === false) {
        return writeResponseOk($response, false);
    }


    $stmt = $conn->prepare("DELETE FROM froody_entry WHERE creationDate <= ?");
    $dateTimeThreeWeeksAgo = new DateTime();
    $dateTimeThreeWeeksAgo->sub(new DateInterval('P21D'));
    $dateTimeParam = FormatUtil::dateTimeToSQLTimestamp($dateTimeThreeWeeksAgo);
    $stmt->bind_param("s", $dateTimeParam);
    if ($stmt->execute()) {
        return writeResponseOk($response, true);
    }
    return writeResponseOk($response, false);
});

function getRandomInt64()
{
    return rand(0, mt_getrandmax());
}

function getRandomInt32()
{
    return rand(0, 2147483647);
}

/**
 * Check if a user with the passed Id exists
 *
 * @param mysqli $conn
 * @param int    $userId
 *
 * @return bool
 */
function doesUserExists($conn, $userId)
{
    $stmt = $conn->prepare('SELECT userId FROM froody_user WHERE userId=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->store_result();

    return $stmt->num_rows === 1;
}

/**
 * Write ok response
 *
 * @param mixed $response
 * @param bool  $success
 *
 * @return mixed
 */
function writeResponseOk($response, $success)
{
    $ok = ResponseOk::create($success);

    return writeResponse($response, $ok, $success ? 200 : 500);
}

/**
 * Write a response to response object. Converts the object to json.
 * Optional httpStatus parameter defaults to 200
 *
 * @param mixed    $response
 * @param mixed    $objectToBeJsonConverted
 * @param integer  $httpStatusCode Defaults to 200
 *
 * @return mixed
 */
function writeResponse($response, $objectToBeJsonConverted, $httpStatusCode = 200)
{
    return $response
        ->withStatus($httpStatusCode)
        ->withHeader('Content-type', 'application/json')
        ->write(json_encode($objectToBeJsonConverted));
}


// ######### RUN application
$app->run();
