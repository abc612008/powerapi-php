<?php

namespace PowerAPI\Data;

/** Handles fetching the transcript and holding onto its data.
 * @property array $sections contains the student's sections
 * @property arrray $information contains the student's information
 */
class Student extends BaseObject
{
    /** URL for the PowerSchool server
     * @var string
     */
    private $soap_url;

    /** session object as returned by PowerSchool
     * @var array
     */
    private $soap_session;


    /**
     * Attempt to authenticate against the server
     * @param string $soap_url URL for the PowerSchool server
     * @param string $soap_session session object as returned by PowerSchool
     * @param boolean $populate should the transcript be immediately populated?
     */
    public function __construct($soap_url, $soap_session, $populate)
    {
        $this->soap_url = $soap_url;
        $this->soap_session = $soap_session;

        $this->details['information'] = Array();
        $this->details['sections'] = Array();

        if ($populate) {
            $this->populate();
        }
    }

    /**
     * Pull the authenticated user's transcript from the server and parses it.
     * @return null
     */
    public function populate()
    {
        $transcript = $this->fetchTranscript();
        $this->parseTranscript($transcript);
    }

    /**
     * Fetches the user's transcript from the server and returns it.
     * @return array user's transcript as returned by PowerSchool
     */
    public function fetchTranscript()
    {
        $client = new \Zend\Soap\Client();
        $client->setOptions(Array(
            'uri' => 'http://publicportal.rest.powerschool.pearson.com/xsd',
            'location' => $this->soap_url.'pearson-rest/services/PublicPortalServiceJSON',
            'login' => 'pearson',
            'password' => 'm0bApP5',
            'use' => SOAP_LITERAL
        ));

        // This is a workaround for SoapClient not having a WSDL to go off of.
        // Passing everything as an object or as an associative array causes
        // the parameters to not be correctly interpreted by PowerSchool.
        $parameters = Array(
            'userSessionVO' => (object) Array(
                'userId' => $this->soap_session->userId,
                'serviceTicket' => $this->soap_session->serviceTicket,
                'serverInfo' => (object) Array(
                    'apiVersion' => $this->soap_session->serverInfo->apiVersion
                ),
                'serverCurrentTime' => $this->soap_session->serverCurrentTime,
                'userType' => $this->soap_session->userType
            ),
            'studentIDs' => $this->soap_session->studentIDs,
            'qil' => (object) Array(
                'includes' => '1'
            )
        );

        $transcript = $client->__call('getStudentData', $parameters);

        return $transcript;
    }
    
    /**
     * Fetches the user's transcript from the server and returns it.
     * @return array user's transcript as returned by PowerSchool
     */
    public function fetchUserPhoto()
    {
        $client = new \Zend\Soap\Client();
        $client->setOptions(Array(
            'uri' => 'http://publicportal.rest.powerschool.pearson.com/xsd',
            'location' => $this->soap_url.'pearson-rest/services/PublicPortalServiceJSON',
            'login' => 'pearson',
            'password' => 'm0bApP5',
            'use' => SOAP_LITERAL
        ));

        // This is a workaround for SoapClient not having a WSDL to go off of.
        // Passing everything as an object or as an associative array causes
        // the parameters to not be correctly interpreted by PowerSchool.
        $parameters = Array(
            'userSessionVO' => (object) Array(
                'userId' => $this->soap_session->userId,
                'serviceTicket' => $this->soap_session->serviceTicket,
                'serverInfo' => (object) Array(
                    'apiVersion' => $this->soap_session->serverInfo->apiVersion
                ),
                'serverCurrentTime' => '2012-12-26T21:47:23.792Z', # I really don't know.
                'userType' => $this->soap_session->userType
            ),
            'studentIDs' => $this->soap_session->studentIDs,
            'qil' => (object) Array(
                'includes' => 'QIL_FEE_TRANSACTIONS'
            )
        );

        $photo = $client->__call('getStudentPhoto', $parameters);

        return $photo;
    }
    /**
     * Parses the passed transcript and populates $this with its contents.
     * @param object $transcript transcript from fetchTranscript()
     * @return void
     */
    public function parseTranscript($transcript)
    {
        $studentData = $transcript->studentDataVOs;
        $this->details['information'] = $studentData->student;
        if($studentData->schools->schoolDisabled=="true"){
            $message = $studentData->schools->schoolDisabledMessage;
            $this->details['disabled'] = (object)array(
                "title" => $studentData->schools->schoolDisabledTitle,
                "message" => $message ? $message . "\n\n(以上消息由学校提供，与 SchoolPower 无关。若有疑问，请联系学校。"
                ."\nThe above message is provided by school and is not related with SchoolPower."
                    ."Please contact your school directly for any questions.)":$message
            );
            $this->details['sections'] = array();
            $this->details['attendances'] = array();
            return;
        }
        
        if (isset($studentData->assignmentCategories))
            $assignmentCategories = \PowerAPI\Parser::assignmentCategories($studentData->assignmentCategories);
        else $assignmentCategories = Array();
        
        if (isset($studentData->assignmentScores))
            $assignmentScores = \PowerAPI\Parser::assignmentScores($studentData->assignmentScores);
        else $assignmentScores = Array();
        
        if (isset($studentData->finalGrades))
            $finalGrades = \PowerAPI\Parser::finalGrades($studentData->finalGrades);
        else $finalGrades = Array();
        
        if (isset($studentData->reportingTerms))
            $reportingTerms = \PowerAPI\Parser::reportingTerms($studentData->reportingTerms);
        else $reportingTerms = Array();
        
        if (isset($studentData->teachers))
            $teachers = \PowerAPI\Parser::teachers($studentData->teachers);
        else $teachers = Array();
        
        if (isset($studentData->citizenGrades) && isset($studentData->citizenCodes))
            $citizenGrades = \PowerAPI\Parser::citizenGrades($studentData->citizenGrades, $studentData->citizenCodes);
        else $citizenGrades = Array();
        
        if (isset($studentData->attendanceCodes))
            $attendanceCodes = \PowerAPI\Parser::groupById($studentData->attendanceCodes);
        else $attendanceCodes = Array();
        
        if (isset($studentData->assignments))
            $assignments = \PowerAPI\Parser::assignments(
                $studentData->assignments,
                $assignmentCategories,
                $assignmentScores,
                $reportingTerms
            );
        else
            $assignments = Array();
            
        if (isset($studentData->sections))
            $this->details['sections'] = \PowerAPI\Parser::sections(
                $studentData->sections,
                $assignments,
                $finalGrades,
                $reportingTerms,
                $teachers,
                $citizenGrades
            );
        else
            $this->details['sections'] = Array();

        if(isset($studentData->attendance))
            $this->details['attendances'] = \PowerAPI\Parser::attendances($studentData->attendance, $attendanceCodes, $studentData->sections);
        else
            $this->details['attendances'] = Array();
    }
}
