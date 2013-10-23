<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class Applications extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('application', function(Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('fqdn');
            $table->string('mysql_name')->nullable();
            $table->string('mysql_user')->nullable();
            $table->string('mysql_pass')->nullable();
            $table->string('git_uri')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('application');
    }

}
