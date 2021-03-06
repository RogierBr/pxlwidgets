<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\ImportFiles;
use App\User;
use App\CreditCard;
use \JsonMachine\JsonMachine;
use Carbon\Carbon;
use DB;


/**
 * Description of CustomersImport
 *
 * @author rogier
 */
class CustomersImport extends Command {
    
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'custom:customers-import';
    
    
     /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import customer data from file';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }
    
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //process found open files
        if($files = self::CheckOpenFiles())
        {
            self::ProcessImportFile($files);
        }
        
        //process new files
        if($files = self::CheckFiles())
        {
           self::ProcessImportFile($files);
        }
        
        //Both new and open files can be processed in in go, one array
        //For clarification these are seperated.
    }
    
    
    /**
     * 
     * Check if there are still files that need processing.
     * This could happen if the import process was interrupted.
     * 
     * @return mixed
     */
    private function CheckOpenFiles()
    {
        $files = ImportFiles::where('status','open')->get();
        $foundfiles = false;
        if($files)
        {
            foreach($files as $f)
            {
                if (Storage::disk('imports')->exists($f->name)) 
                {
                    //check if file on disk is not a new file
                    $size = Storage::disk('imports')->size($f->name);
                    $lastmodified = Storage::disk('imports')->lastModified($f->name);
                    if($lastmodified == $f->file_created_at && $size == $f->size_on_disk)
                    {
                        $foundfiles[] = $f;
                    }
                }
            }
        }
        
        return $foundfiles;
    }
    
    /**
     * Check if there are new files on disk
     * I assume target location is an (s)ftp folder of sorts, 
     * this makes more sense then manual uploads, which are not automated. 
     * I also assume it is updated regularly (daily).
     * 
     */
    private function CheckFiles()
    {
        $files = Storage::disk('imports')->files();
        
        $foundfiles = false;
        
        foreach($files as $f)
        {
            $size = Storage::disk('imports')->size($f);
            $lastmodified = Storage::disk('imports')->lastModified($f);
            
            $foundfiles[] = [
                            'id'=>0,
                            'name'=>$f,
                            'size_on_disk'=>$size,
                            'file_created_at'=>$lastmodified,
                            'records'=>0
                            ];
        }
        return $foundfiles;
    }
    
    private function ProcessImportFile($files)
    {
        if(is_array($files))
        {
            foreach($files as $f)
            {
                $f = (object) $f; //being lazy, -> is shorter then['']
                
                if(isset($f->id))
                {
                    if($f->id === 0)
                    {
                        //save a record for prosperity
                        $newimportfiles = new ImportFiles;

                        $newimportfiles->name = $f->name;
                        $newimportfiles->size_on_disk = $f->size_on_disk;
                        $newimportfiles->file_created_at = $f->file_created_at;

                        $newimportfiles->save();
                        $f->id = $newimportfiles->id;
                    }
                    
                    //Right now we only have JSON.
                    self::DoImportJSON($f->name, $f->id, $f->records);
                    //Otherwise there would be a function that checks 
                    //extension and/or MIME type before callinf the right method.
                }
            }
        }
    }
    
    /*
     * Do the actual JSON import. Because we only have one format, 
     * no need to split the loop and inserts in multiple reusable methods 
     * 
     * There is no test on duplicate record, they are imported 1 on 1.
     * 
     */
    private function DoImportJSON($filename, $id, $record=0)
    {
        /**
         * JsonMachine is used because it can stream a file unlike file_get_contents
         * or other methods that use Memory. As I assume files can be huge, 
         * in memory solutions will not work.
         */
        
        $users = JsonMachine::fromFile(Storage::disk('imports')->path($filename));
        
        $current_record = 1;
        foreach($users as $user=>$u)
        {
            set_time_limit(0);
            
            $u = self::CleanupBirthdate($u);
            
            if($current_record > $record && self::ImportAllowed($u))
            {
                DB::transaction(function () use ($u, $id, $current_record){
                    //insert the record in users and creditcard.
                    $newuser = new User;
                    
                    $newuser->name          = $u['name'];
                    $newuser->address       = $u['address'];
                    $newuser->checked       = $u['checked'];
                    $newuser->description   = $u['description'];
                    $newuser->interest      = $u['interest'];
                    $newuser->date_of_birth = $u['date_of_birth'];
                    $newuser->email         = $u['email'];
                    $newuser->account       = $u['account'];
                    $newuser->current_record = $current_record;
                    $newuser->import_file_id = $id;
                    
                    $newuser->save();
                    
                    $user_id = $newuser->id;
                    
                    //fill the creditcard
                    $newcc = new CreditCard;

                    $newcc->user_id     = $user_id;
                    $newcc->type        = $u['credit_card']['type'];
                    $newcc->number      = $u['credit_card']['number'];
                    $newcc->name        = $u['credit_card']['name'];
                    $newcc->expiration_date = date('Y-m-d', strtotime('01/'.$u['credit_card']['expirationDate']));

                    $newcc->save();
                   
                   //update ImportFiles with record number
                   ImportFiles::where('id',$id)->update(['records'=>$current_record]);
                });
                $current_record++;
                
                #Test partial imports
                #if($current_record > 5) break;
            }
        }
        
        //file import finished so clean up time.
        ImportFiles::where('id',$id)->update(['status'=>'imported']);
        Storage::disk('imports')->move($filename, '/processed/'.$filename);
        
    }
    
    /**
     * Seem birthdays are a bit messy, cleanup please
     * 
     * @param type $u
     * @return type
     */
    private function CleanupBirthdate($u)
    {
        if(!is_null($u['date_of_birth']) && !empty($u['date_of_birth']))
        {
            $u['date_of_birth'] = str_replace('/', '-', $u['date_of_birth']);
            $u['date_of_birth'] = date('Y-m-d', strtotime($u['date_of_birth']));
        }
           
        return $u;
    }
    
    /**
     * Test if a record ia allowed to be imported. 
     * Can be extended with multiple tests
     * 
     * @param type $u
     * @return boolean
     */
    private function ImportAllowed($u)
    {
        //available tests: ['age', 'creditcard'];
        $tests = ['age'];
                
        foreach($tests as $test)
        {
            $result = false;
            if(method_exists($this, 'test_'.$test))
            {
                $t = 'test_'.$test;
                $result =  self::$t($u);
               
                //return the first false, no need to process all tests
                if(!$result) return $result;
            }
        }
        
        // No false found, continue
        return true;
    }
    
    /**
     * Check if ages are between 18 and 65
     * 
     * @param type $u
     * @return boolean
     */
    private function test_age($u)
    {
        if(!is_null($u['date_of_birth']) && !empty($u['date_of_birth']))
        {
            $age = Carbon::parse(date('Y-m-d', strtotime($u['date_of_birth'])))->age;
            if($age > 17 && $age < 66)
                return true;
            else
                return false;
        }
        
        return true;
    }
    
    private function test_creditcard($u)
    {
        if(!is_null($u['credit_card']['number']) && !empty($u['credit_card']['number']))
        {
            if($r = preg_match('/(\d)\1{2}/',$u['credit_card']['number']))
                return true;
            else 
                return false;
        }
        return false;
    }
    
}