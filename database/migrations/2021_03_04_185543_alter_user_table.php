<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterUserTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //
        Schema::table('users', function (Blueprint $table) {
            $table->string('address');
            $table->integer('import_file_id')->unsigned()->index();
            $table->date('date_of_birth')->nullable();
            $table->boolean('checked')->default(false);
            $table->text('description')->nullable();
            $table->string('interest')->nullable();
            $table->string('account')->nullable();
            $table->integer('current_record')->default(0);
            
        });
        
        
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
