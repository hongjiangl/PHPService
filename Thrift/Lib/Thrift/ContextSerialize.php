<?php
namespace Thrift;
use Thrift\Type\TType; 

class ContextSeralize {
  
    private static $_parrotContext = '$$$_1_PC$$$$$$$$__1_&_8_#';
    public $arg0 = null;

  public function __construct($vals=null) {
    if (is_array($vals)) {
       $this->arg0 = $vals;
    }
  }
  
  public function read($input)
  {
    global $context;  
    $this->arg0 = $context;
    
    $xfer = 0;
    $_size7 = 0;
    $_ktype8 = 0;
    $_vtype9 = 0;
   
    $rseqid = 0;
    $fname = null;
    $mtype = 0;

    $xfer += $input->readMessageBegin($fname, $mtype, $rseqid);
    if ($fname != self::$_parrotContext) return false;
   // $xfer += $input->readMessageBegin();
    $xfer += $input->readMapBegin($_ktype8, $_vtype9, $_size7);
    for ($_i11 = 0; $_i11 < $_size7; ++$_i11)
    {
      $key12 = '';
      $val13 = '';
      $xfer += $input->readString($key12);
      $xfer += $input->readString($val13);
      $this->arg0[$key12] = $val13;    
    }
    $xfer += $input->readMapEnd();
    $xfer += $input->readMessageEnd();
    $context=$this->arg0;
    return $xfer;
  }

  public function write($output) {
    $xfer = 0;
    if ($this->arg0 !== null) {
      if (!is_array($this->arg0)) {
        throw new TProtocolException('Context is not a array', TProtocolException::INVALID_DATA);
      }
    
    $xfer += $output->writeMessageBegin(self::$_parrotContext, \Thrift\Type\TMessageType::CALL, 0);
    $xfer += $output->writeMapBegin(\Thrift\Type\TType::STRING, TType::STRING, count($this->arg0));
    foreach ($this->arg0 as $kiter14 => $viter15)
    {
      $xfer += $output->writeString($kiter14);
      $xfer += $output->writeString($viter15);
    }
    $output->writeMapEnd();
    $output->writeMessageEnd();
    }
    return $xfer;
  }
}
