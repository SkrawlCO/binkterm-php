/* Light Up presentation/input transport. No puzzle rules or solver here. */
#define _POSIX_C_SOURCE 200809L
#include <stdarg.h>
#include <string.h>
#include "puzzles.h"
static midend *me;
static int lit[7][7], errors[7][7];
static char *saved;
static size_t saved_len,read_pos;
void fatal(const char *fmt, ...) {va_list a;va_start(a,fmt);vfprintf(stderr,fmt,a);va_end(a);exit(1);}
void frontend_default_colour(frontend *f,float *out){out[0]=out[1]=out[2]=0.8f;}
void get_random_seed(void **p,int *n){*p=dupstr("L33TEST-M1");*n=10;}
void activate_timer(frontend *f){}
void deactivate_timer(frontend *f){}
static int cell(int p){int t=midend_tilesize(me);return (p-t/2)/t;}
/* Colour indices are presentation constants from this pinned lightup.c. */
static void rect(drawing *d,int x,int y,int w,int h,int col){int t=midend_tilesize(me);int c=cell(x),r=cell(y);if(c>=0&&c<7&&r>=0&&r<7&&w==t&&h==t)lit[r][c]=(col==4);}
static void text(drawing *d,int x,int y,int ft,int fs,int al,int col,const char *s){int c=cell(x),r=cell(y);if(c>=0&&c<7&&r>=0&&r<7&&col==5)errors[r][c]=1;}
static void circle(drawing *d,int x,int y,int radius,int fill,int outline){int c=cell(x),r=cell(y);if(c>=0&&c<7&&r>=0&&r<7&&fill==5)errors[r][c]=1;}
static void line(drawing*d,int a,int b,int c,int e,int f){}
static void polygon(drawing*d,const int*a,int n,int f,int o){}
static void region(drawing*d,int a,int b,int c,int e){}
static void noop(drawing*d){}
static void status(drawing*d,const char*s){}
static const drawing_api api={.version=1,.draw_text=text,.draw_rect=rect,.draw_line=line,.draw_polygon=polygon,.draw_circle=circle,.draw_update=region,.clip=region,.unclip=noop,.start_draw=noop,.end_draw=noop,.status_bar=status};
struct buffer {char *data;size_t len;};
static void writer(void *ctx,const void *buf,int n){struct buffer*b=ctx;b->data=realloc(b->data,b->len+n+1);memcpy(b->data+b->len,buf,n);b->len+=n;b->data[b->len]=0;}
static bool reader(void *ctx,void *buf,int n){if(read_pos+n>saved_len)return false;memcpy(buf,saved+read_pos,n);read_pos+=n;return true;}
static void quoted(const char*s){putchar('"');for(;*s;s++){unsigned char c=*s;if(c=='"'||c=='\\'){putchar('\\');putchar(c);}else if(c=='\n')printf("\\n");else if(c=='\r')printf("\\r");else if(c=='\t')printf("\\t");else if(c<32)printf("\\u%04x",c);else putchar(c);}putchar('"');}
static void frame(int result,const char *error){
    memset(lit,0,sizeof(lit));memset(errors,0,sizeof(errors));midend_stop_anim(me);midend_force_redraw(me);
    char *ascii=midend_text_format(me),*id=midend_get_game_id(me);int x=0,y=0,w=0,h=0;
    bool cursor=midend_get_cursor_location(me,&x,&y,&w,&h);
    struct buffer b={0};midend_serialise(me,writer,&b);
    printf("{\"ascii\":");quoted(ascii);printf(",\"id\":");quoted(id);
    printf(",\"save\":");quoted(b.data);printf(",\"status\":%d,\"result\":%d,\"cursor\":[%d,%d],\"error\":",midend_status(me),result,cursor?cell(x):-1,cursor?cell(y):-1);quoted(error?error:"");
    printf(",\"lit\":[");for(int r=0;r<7;r++){if(r)putchar(',');putchar('[');for(int c=0;c<7;c++){if(c)putchar(',');printf("%d",lit[r][c]);}putchar(']');}
    printf("],\"errors\":[");for(int r=0;r<7;r++){if(r)putchar(',');putchar('[');for(int c=0;c<7;c++){if(c)putchar(',');printf("%d",errors[r][c]);}putchar(']');}printf("]}\n");fflush(stdout);
    sfree(ascii);sfree(id);free(b.data);
}
int main(int argc,char **argv){
    setenv("PUZZLES_SHOW_CURSOR","true",1);
    me=midend_new(NULL,&thegame,&api,NULL);
    const char *err=midend_game_id(me,"7x7:cBd0c1hBe2h1c0d0c");if(err)fatal("%s\n",err);
    midend_new_game(me);int w=256,h=256;midend_size(me,&w,&h,false,1.0);
    frame(-1,NULL);char cmd[256];
    while(fgets(cmd,sizeof(cmd),stdin)){
        int result=-1;err=NULL;
        if(!strncmp(cmd,"quit",4))break;
        if(!strncmp(cmd,"import ",7)){
            unsigned long n = strtoul(cmd+7,NULL,10);
            if (!n || n>90000) fatal("Invalid import size");
            free(saved); saved_len=n; saved=malloc(n+1);
            if (fread(saved,1,n,stdin)!=n) fatal("Incomplete import");
            read_pos=0; err=midend_deserialise(me,reader,NULL);
            midend_size(me,&w,&h,false,1.0);
        }
        else if(!strncmp(cmd,"restart",7))midend_restart_game(me);
        else if(!strncmp(cmd,"save",4)){struct buffer b={0};midend_serialise(me,writer,&b);free(saved);saved=b.data;saved_len=b.len;}
        else if(!strncmp(cmd,"load",4)){if(!saved)err="No saved state";else{read_pos=0;err=midend_deserialise(me,reader,NULL);midend_size(me,&w,&h,false,1.0);}}
        else if(!strncmp(cmd,"key ",4)){
            int k=cmd[4];
            switch(k){case 'w':k=CURSOR_UP;break;case 'a':k=CURSOR_LEFT;break;case 's':k=CURSOR_DOWN;break;case 'd':k=CURSOR_RIGHT;break;case ' ':k=CURSOR_SELECT;break;case 'x':k=CURSOR_SELECT2;break;case 'u':k=UI_UNDO;break;case 'y':k=UI_REDO;break;}
            result=midend_process_key(me,0,0,k);
        }
        frame(result,err);
    }
    midend_free(me);free(saved);return 0;
}
