<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
// use App\Models\Barang;
use App\Models\Opname;
use App\Models\DetailOpname;
use App\Models\TransaksiPenjualan;
use App\Models\Gaji;
use App\Models\DetailGaji;
use App\Models\TransferPersediaan;
use App\Models\DetailTransferPersediaan;
use App\Models\User;
use App\Models\Gudang;
use App\Models\Pegawai;
use App\Models\Barang;
use App\Models\Cabang;



use PDF;


class LaporanController extends Controller
{



    public function laporanTransaksiPenjualan(Request $payload){

        $tanggal_awal = $payload->input('tanggal_awal');
        $tanggal_akhir = $payload->input('tanggal_akhir');
        $cabang_id = $payload->input('cabang_id');
        $status = $payload->input('status');

        if($status == 'SEMUA'){ // SEMUA
            $master = TransaksiPenjualan::where('cabang_id', $cabang_id)
            ->whereBetween('created_at',[$tanggal_awal, $tanggal_akhir])->get()->sortBy([['created_at','desc']]);
        }else if($status== 'BELUM LUNAS'){ // BELUM LUNAS
            $master = TransaksiPenjualan::where('cabang_id', $cabang_id)
            ->where('sisa_pembayaran', '!=', 0)
            ->whereBetween('created_at',[$tanggal_awal, $tanggal_akhir])->get()->sortBy([['created_at','desc']]);
        }else if($status == 'LUNAS'){
            $master = TransaksiPenjualan::where('cabang_id', $cabang_id)
            ->where('sisa_pembayaran', 0)
            ->whereBetween('created_at',[$tanggal_awal, $tanggal_akhir])->get()->sortBy([['created_at','desc']]);
        }


        $output = [];
        foreach ($master as $key => $value) {
            $invoice = [
                'diskon'=>$value->diskon,
                'grandTotal'=>$value->grand_total,
                'ongkir'=>$value->ongkir,
                'pajak'=>$value->pajak_keluaran,    
                'total'=>$value->total,
            ];
    
            $user = User::join('master_pegawai','users.pegawai_id','=','master_pegawai.id')
            ->where('users.id','=',$value->user_id)->first(['users.*', 'master_pegawai.nama']);

            $sales = Pegawai::where('id',$value->sales_id)->first();
    
            $pelanggan = DB::table('master_kontak')
            ->where('id','=',$value->kontak_id)
            ->first();
    
            $bank = DB::table('master_bank')
            ->where('id','=',$value->bank_id)
            ->first();
    
            $pembayaran = [
                'bank'=>$bank,
                'downPayment'=>$value->down_payment,
                'sisaPembayaran'=>$value->sisa_pembayaran,
                'jenisPembayaran' => app('App\Http\Controllers\TransaksiPenjualanController')->caraPembayaran($value->cara_pembayaran),
                'kredit'=>$value->kredit,
                'statusPembayaran'=>app('App\Http\Controllers\TransaksiPenjualanController')->metodePembayaran($value->metode_pembayaran),
                'tanggalJatuhTempo'=>$value->tanggal_jatuh_tempo,
                'status'=>$value->sisa_pembayaran == 0 ? 'LUNAS' : 'BELUM LUNAS',
            ];
    
            $data = [
                'id'=>$value->id,
                'catatan'=>$value->id,
                'retur'=>$value->retur,
                'nomorTransaksi'=>$value->nomor_transaksi,
                'tanggalTransaksi'=>$value->created_at->format('d F Y'),
                'nomorJurnal' => $value->nomor_jurnal,
                'invoice'=> $invoice,
                'pelanggan'=>$pelanggan,
                'pembayaran'=>$pembayaran,
                'user'=> $user,
                'sales'=>$sales

            ];
    
            $output[] = $data;
        }

        $pdf = PDF::loadview('laporan.transaksi_penjualan',['master'=>$output,'payload'=>$payload]);
    	return $pdf->download('laporan-persediaan'.$tanggal_awal.'-'.$tanggal_akhir.'.pdf');
        // return view('laporan.transaksi_penjualan',['master'=>$output,'payload'=>$payload]);
    }

    public function laporanPersediaan(Request $payload){

        $tanggal_awal = date('Y-01-01');
        $tanggal_akhir = $payload->input('tanggal_akhir');
        // if($tanggal_akhir = null){
        //     $tanggal_akhir = date('Y-m-d');
        // }
        $gudang_id = $payload->input('gudang_id');
        $cabang_id = $payload->input('cabang_id');

        $gudang = Gudang::find($gudang_id);

        $output =[];
        $data = DB::table('barang')
        ->join('jenis_barang', 'barang.jenis_id', '=', 'jenis_barang.id')
        ->join('merek_barang', 'barang.merek_id', '=', 'merek_barang.id')
        ->join('gudang', 'barang.gudang_id', '=', 'gudang.id')
        ->select('barang.*', 'gudang.nama as nama_gudang','jenis_barang.nama as nama_jenis', 'merek_barang.nama as nama_merek')
        ->where('barang.deleted_at', '=',null)
        ->get();

        
        foreach ($data as $key => $value) {

            $xx = DB::table('kartu_persediaan')
            ->where('gudang_id', '=',$gudang_id)
            ->where('cabang_id', '=',$cabang_id)
            ->where('master_barang_id', '=',$value->id)
            ->where('deleted_at')
            ->pluck('gudang_id')->first();

            $saldo_masuk = DB::table('kartu_persediaan')
            ->whereDate('created_at','>=', $tanggal_awal)
            ->whereDate('created_at','<=', $tanggal_akhir)
            ->where('gudang_id', '=',$gudang_id)
            ->where('cabang_id', '=',$cabang_id)
            ->where('master_barang_id', '=',$value->id)
            ->where('deleted_at')
            ->where('jenis', '=','DEBIT')
            ->sum('jumlah');

            $saldo_keluar = DB::table('kartu_persediaan')
            ->whereDate('created_at','>=', $tanggal_awal)
            ->whereDate('created_at','<=', $tanggal_akhir)
            ->where('master_barang_id', '=',$value->id)
            ->where('gudang_id', '=',$gudang_id)
            ->where('cabang_id', '=',$cabang_id)
            ->where('deleted_at')
            ->where('jenis', '=','KREDIT')
            ->sum('jumlah');


            // ->sum('saldo_rupiah');
            $saldo = $saldo_masuk - $saldo_keluar;
            $saldo_rupiah = 0;
            if($saldo < 0){
               $barang =  Barang::find($value->id);
               $saldo_rupiah = $saldo * $barang->harga_beli;
            }else if ($saldo > 0){
                $saldo_rupiah = DB::table('harga_beli')
                ->select(DB::raw('sum(saldo * harga_beli) as saldo_rupiah'))
                ->whereDate('created_at','>=', $tanggal_awal)
                ->whereDate('created_at','<=', $tanggal_akhir)
                ->where('gudang_id', '=',$gudang_id)
                ->where('cabang_id', '=',$cabang_id)
                ->where('master_barang_id', '=',$value->id)
                ->where('deleted_at')
                ->where('saldo', '!=','0')->first();
                $saldo_rupiah = $saldo_rupiah->saldo_rupiah;
            }

            if($xx==null){
                continue;
            }
            $value->persediaan['saldo'] = $saldo_masuk - $saldo_keluar;
            $value->persediaan['saldo_masuk'] = $saldo_masuk;
            $value->persediaan['saldo_keluar'] = $saldo_keluar;
            $value->persediaan['saldo_rp'] = $saldo_rupiah;
            $value->persediaan['gudang_id'] = $xx;
            $output[] = $value;
        }
        $pdf = PDF::loadview('laporan.persediaan',['master'=>$output,'payload'=>$payload,'gudang'=> $gudang]);
    	return $pdf->download('laporan-'.$tanggal_akhir.'persediaan.pdf');
        return view('laporan.persediaan',['master'=>$output,'payload'=>$payload,'gudang'=> $gudang]);
        // return response()->json($gudang, 200);
    }

    public function laporanPersediaanOpname(Request $payload){
        $id = $payload->input('id');

        $master = Opname::find($id);

        $detail = DetailOpname::where('master_opname_id', $master->id)->get();
        foreach ($detail as $key => $data) {
            $barang = Barang::where('id',$data->master_barang_id)->first();
            $data->nama = $barang->nama;
            $data->kode_barang = $barang->kode_barang;
            $detail[] = $data;
        }
        $master->detail = $detail;

        return view('laporan.persediaan-opname',['master'=>$master]);
        return response()->json($master, 200);
    }

    public function laporanPersediaanTransfer(Request $payload){
        $id = $payload->input('id');

        $master = TransferPersediaan::findOrFail($id);
        $dari = Gudang::find($master->dari);
        $master->dari = $dari;
        $ke = Gudang::find($master->ke);
        $master->ke = $ke;

        $detail = DetailTransferPersediaan::where('master_transfer_persediaan_id', $master->id)->get();

        foreach ($detail as $key => $data) {
            $barang = Barang::where('id',$data->master_barang_id)->first();
            $data->nama = $barang->nama;
            $data->kode_barang = $barang->kode_barang;
            $output[] = $data;
        }
        $master->detail = $output;

        return view('laporan.persediaan-transfer',['master'=>$master]);
        // return response()->json($master, 200);
    }

    public function gaji(Request $payload){

        $id = $payload->input('id');

        $master = Gaji::findOrFail($id);
        // return $master;

        $detail = DetailGaji::select('detail_gaji.*','master_pegawai.nama as nama_pegawai','master_jabatan.nama as nama_jabatan')
        ->join('master_pegawai', 'detail_gaji.pegawai_id', '=', 'master_pegawai.id')
        ->join('master_jabatan', 'master_pegawai.jabatan_id', '=', 'master_jabatan.id')
        ->where('detail_gaji.master_gaji_id', $id)->get();
        $total['gaji_pokok'] = 0;
        $total['uang_makan'] = 0;
        $total['bonus'] = 0;
        $total['grand_total'] = 0;
        foreach ($detail as $key => $value) {
            $total['gaji_pokok'] += $value->gaji_pokok;
            $total['uang_makan'] += $value->uang_makan;
            $total['bonus'] += $value->bonus;
            $total['grand_total'] += $value->gaji_pokok + $value->uang_makan + $value->bonus;
        }
        return view('laporan.gaji',['detail'=>$detail,'master'=>$master,'total'=>$total]);
    	// $pdf = PDF::loadview('laporan.gaji',['detail'=>$detail,'master'=>$master,'total'=>$total]);
    	// return $pdf->download('laporan-'.$master->created_at->format('d-m-y').'gaji.pdf');
    }

    public function cabang(Request $payload){

        $id = $payload->input('cabang_id');
        $bulan = $payload->input('bulan');
        $tanggal = $payload->input('tanggal');
        if($bulan != null){
            $month = $this->getMonthNumber($bulan);
            $param2 = 'bulan='.$month;
        }else{
            $param2 = 'tanggal='.$tanggal;
        }



        $master = Cabang::findOrFail($id);
        $user = User::where('cabang_id', $master->id)->get();
        $gudang = Gudang::where('cabang_id', $master->id)->get();
        $saldo_kas = 0;
        $saldo_persediaan = 0;
        $saldo_beban_operasional = 0;
        foreach ($user as $key => $value) {
            $saldo = Http::get(keuanganBaseUrl().'akun/cek-saldo?id='.$value->kode_akun_id.'&cabang_id='.$id.'&'.$param2)->json();
            $saldo_kas += $saldo;
        }
        foreach ($gudang as $key => $value) {
            $saldo = Http::get(keuanganBaseUrl().'akun/cek-saldo?id='.$value->kode_akun_id.'&cabang_id='.$id.'&'.$param2)->json();
            $saldo_persediaan += $saldo;
        }
        $saldo_penjualan = Http::get(keuanganBaseUrl().'akun/cek-saldo?id=32&cabang_id='.$id.'&'.$param2)->json();
        $saldo_beban_operasional = Http::get(keuanganBaseUrl().'akun/cek-saldo?id=42&cabang_id='.$id.'&'.$param2)->json();
        // INJECT
        $master->kas = $saldo_kas;
        $master->penjualan = $saldo_penjualan;
        $master->persediaan = $saldo_persediaan;
        $master->beban_operasional = $saldo_beban_operasional;

        return $master;
    
        return view('laporan.cabang',['payload'=>$payload,'master'=>$master]);
    	// $pdf = PDF::loadview('laporan.gaji',['detail'=>$detail,'master'=>$master,'total'=>$total]);
    	// return $pdf->download('laporan-'.$master->created_at->format('d-m-y').'gaji.pdf');
    }

    function getMonthNumber($monthStr) {
        //e.g, $month='Jan' or 'January' or 'JAN' or 'JANUARY' or 'january' or 'jan'
        $m = ucfirst(strtolower(trim($monthStr)));
        switch ($monthStr) {
            case "JANUARI":        
            case "Jan":
                $m = "01";
                break;
            case "FEBRUARI":
            case "Feb":
                $m = "02";
                break;
            case "MARET":
            case "Mar":
                $m = "03";
                break;
            case "APRIL":
            case "Apr":
                $m = "04";
                break;
            case "MEI":
                $m = "05";
                break;
            case "JUNI":
            case "Jun":
                $m = "06";
                break;
            case "JULI":        
            case "Jul":
                $m = "07";
                break;
            case "AGUSTUS":
            case "Aug":
                $m = "08";
                break;
            case "SEPTEMBER":
            case "Sep":
                $m = "09";
                break;
            case "OKTOBER":
            case "Oct":
                $m = "10";
                break;
            case "NOVEMBER":
            case "Nov":
                $m = "11";
                break;
            case "DESEMBER":
            case "Dec":
                $m = "12";
                break;
            default:
                $m = false;
                break;
        }
        return $m;
        }
}
